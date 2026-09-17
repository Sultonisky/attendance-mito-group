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
        );
    }

    private function makeStoreWithPostGis(float $lat, float $lng, float $radius = 150): WorkLocation
    {
        return WorkLocation::factory()->create([
            'latitude' => $lat,
            'longitude' => $lng,
            'radius_meters' => $radius,
            'location_point' => \DB::raw('ST_SetSRID(ST_MakePoint('.$lng.', '.$lat.'), 4326)'),
        ]);
    }

    private function makeActiveAssignment(Outsource $outsource, WorkLocation $store): OutsourceStoreAssignment
    {
        return OutsourceStoreAssignment::factory()
            ->forOutsource($outsource)
            ->forStore($store)
            ->create(['status' => 'active']);
    }

    private function makeOperationData(float $lat, float $lng, int $workLocationId, ?CarbonImmutable $at = null): AttendanceOperationData
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
        );
    }

    public function test_outsource_inside_150m_geofence_is_allowed_with_postgis(): void
    {
        $store = $this->makeStoreWithPostGis(-6.2, 106.8, 150);
        $outsource = Outsource::factory()->create(['status' => 'active']);
        $this->makeActiveAssignment($outsource, $store);

        $engine = $this->makeEngine();
        $result = $engine->checkIn($outsource, $this->makeOperationData(-6.2001, 106.8001, $store->id));

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

        $engine->checkIn($outsource, $this->makeOperationData(-6.3, 106.9, $store->id));
    }

    public function test_outsource_geofence_uses_exactly_150m_regardless_of_store_radius(): void
    {
        $store = $this->makeStoreWithPostGis(-6.2, 106.8, 500);
        $outsource = Outsource::factory()->create(['status' => 'active']);
        $this->makeActiveAssignment($outsource, $store);

        $engine = $this->makeEngine();

        // Exactly at the 150m boundary should be inside (ST_DWithin uses <=).
        $result = $engine->checkIn($outsource, $this->makeOperationData(-6.198644, 106.8, $store->id));
        $this->assertTrue($result->geofence['passed']);

        // Beyond 150m should be rejected even if the store radius is 500m.
        $this->expectException(\App\Domain\Attendance\Exceptions\OutsideGeofenceException::class);
        $engine->checkIn($outsource, $this->makeOperationData(-6.198553, 106.8, $store->id));
    }
}
