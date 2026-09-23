<?php

namespace Tests\Integration\PostgreSQL;

use App\Domain\Attendance\DTOs\AttendanceOperationData;
use App\Domain\Attendance\Engines\AttendanceEngine;
use App\Domain\Attendance\Rules\GeofenceRule;
use App\Domain\Attendance\Rules\GpsValidationRule;
use App\Domain\Policy\Engines\PolicyEngine;
use App\Domain\Schedule\Engines\ScheduleEngine;
use App\Enums\AttendanceEventType;
use App\Models\Outsource;
use App\Models\OutsourceStoreAssignment;
use App\Models\WorkLocation;
use App\Models\WorkLocationPin;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OutsourceGeofencePostgresTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->refreshDatabase();
    }

    private function makeEngine(): AttendanceEngine
    {
        return new AttendanceEngine(
            new PolicyEngine,
            new ScheduleEngine,
            new GpsValidationRule,
            new GeofenceRule,
            new \App\Domain\Attendance\Rules\LateDetectionRule,
            new \App\Domain\Attendance\Rules\EarlyCheckoutRule,
            new \App\Domain\Attendance\Rules\AttendanceStateRule,
            new \App\Domain\Attendance\Services\OutsourceSessionExpiry,
            new \App\Services\Outsource\ResolveOutsourceAllowedPins,
        );
    }

    private function makeStoreWithPostGis(float $lat, float $lng, float $radius = 150): WorkLocation
    {
        $store = WorkLocation::factory()->create([
            'latitude' => $lat,
            'longitude' => $lng,
            'radius_meters' => $radius,
            'location_point' => \DB::raw('ST_SetSRID(ST_MakePoint('.$lng.', '.$lat.'), 4326)'),
        ]);

        $pin = WorkLocationPin::factory()
            ->forLocation($store)
            ->atCoordinates($lat, $lng, $radius)
            ->create([
                'name' => $store->name,
                'status' => 'active',
            ]);

        if (\DB::getDriverName() === 'pgsql') {
            \DB::statement(
                'UPDATE work_location_pins SET location_point = ST_SetSRID(ST_MakePoint(?, ?), 4326) WHERE id = ?',
                [$lng, $lat, $pin->id]
            );
        }

        return $store;
    }

    private function defaultPin(WorkLocation $store): WorkLocationPin
    {
        return WorkLocationPin::query()
            ->where('work_location_id', $store->id)
            ->orderBy('id')
            ->firstOrFail();
    }

    private function makeActiveAssignment(Outsource $outsource, WorkLocation $store): OutsourceStoreAssignment
    {
        return OutsourceStoreAssignment::factory()
            ->forOutsource($outsource)
            ->forStore($store)
            ->create(['status' => 'active']);
    }

    private function makeOperationData(float $lat, float $lng, int $workLocationId, ?CarbonImmutable $at = null, ?int $pinId = null): AttendanceOperationData
    {
        return new AttendanceOperationData(
            employeeId: 0,
            latitude: $lat,
            longitude: $lng,
            accuracy: 12.5,
            deviceIdentifier: 'test-device',
            source: 'mobile',
            workLocationId: $workLocationId,
            occurredAt: $at ?? CarbonImmutable::now(),
            eventType: AttendanceEventType::CheckIn,
            pinId: $pinId,
        );
    }

    public function test_outsource_inside_150m_geofence_is_allowed_with_postgis(): void
    {
        $store = $this->makeStoreWithPostGis(-6.2, 106.8, 150);
        $outsource = Outsource::factory()->create(['status' => 'active']);
        $this->makeActiveAssignment($outsource, $store);

        $engine = $this->makeEngine();
        $result = $engine->checkIn($outsource, $this->makeOperationData(-6.2001, 106.8001, $store->id, null, $this->defaultPin($store)->id));

        $this->assertNotNull($result->attendanceRecord);
        $this->assertTrue($result->geofence['passed']);
    }

    public function test_outsource_outside_150m_geofence_is_rejected_with_postgis(): void
    {
        $store = $this->makeStoreWithPostGis(-6.2, 106.8, 150);
        $outsource = Outsource::factory()->create(['status' => 'active']);
        $this->makeActiveAssignment($outsource, $store);

        $engine = $this->makeEngine();

        $this->expectException(\App\Domain\Attendance\Exceptions\OutsideGeofenceException::class);

        $engine->checkIn($outsource, $this->makeOperationData(-6.3, 106.9, $store->id, null, $this->defaultPin($store)->id));
    }

    public function test_outsource_geofence_uses_pin_radius_meters(): void
    {
        // Pin radius 500m (store radius is irrelevant for outsource pin geofence).
        $store = $this->makeStoreWithPostGis(-6.2, 106.8, 500);
        $outsource = Outsource::factory()->create(['status' => 'active']);
        $this->makeActiveAssignment($outsource, $store);

        $engine = $this->makeEngine();
        $pinId = $this->defaultPin($store)->id;

        // ~200m away — inside 500m pin radius.
        $result = $engine->checkIn($outsource, $this->makeOperationData(-6.1982, 106.8, $store->id, null, $pinId));
        $this->assertTrue($result->geofence['passed']);

        // Far outside 500m — rejected.
        $this->expectException(\App\Domain\Attendance\Exceptions\OutsideGeofenceException::class);
        $engine->checkIn($outsource, $this->makeOperationData(-6.3, 106.9, $store->id, null, $pinId));
    }
}
