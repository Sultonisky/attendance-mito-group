<?php

namespace Tests\Feature\Outsource;

use App\Domain\Attendance\DTOs\AttendanceOperationData;
use App\Domain\Attendance\Engines\AttendanceEngine;
use App\Domain\Attendance\Rules\GeofenceRule;
use App\Domain\Attendance\Rules\GpsValidationRule;
use App\Domain\Policy\Engines\PolicyEngine;
use App\Domain\Schedule\Engines\ScheduleEngine;
use App\Enums\AttendanceEventType;
use App\Models\AttendanceEvent;
use App\Models\AttendanceRecord;
use App\Models\AttendanceSession;
use App\Models\Outsource;
use App\Models\OutsourceStoreAssignment;
use App\Models\WorkLocation;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OutsourceAttendanceTest extends TestCase
{
    use RefreshDatabase;

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
        );
    }

    private function makeStore(float $lat, float $lng, float $radius = 150): WorkLocation
    {
        return WorkLocation::factory()->create([
            'latitude' => $lat,
            'longitude' => $lng,
            'radius_meters' => $radius,
        ]);
    }

    private function makeActiveAssignment(Outsource $outsource, WorkLocation $store): OutsourceStoreAssignment
    {
        return OutsourceStoreAssignment::factory()
            ->forOutsource($outsource)
            ->forStore($store)
            ->create(['status' => 'active']);
    }

    private function makeOperationData(float $lat, float $lng, ?int $workLocationId = null, ?CarbonImmutable $at = null): AttendanceOperationData
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

    public function test_active_outsource_with_valid_assignment_inside_geofence_can_check_in(): void
    {
        $outsource = Outsource::factory()->create(['status' => 'active']);
        $store = $this->makeStore(-6.2, 106.8, 150);
        $this->makeActiveAssignment($outsource, $store);

        $engine = $this->makeEngine();
        $result = $engine->checkIn($outsource, $this->makeOperationData(-6.2001, 106.8001, $store->id));

        $this->assertNotNull($result->attendanceRecord);
        $this->assertSame('outsource', $result->attendanceRecord->attendable_type);
        $this->assertSame($outsource->id, $result->attendanceRecord->outsource_id);
        $this->assertNull($result->attendanceRecord->employee_id);
    }

    public function test_outsource_check_in_outside_geofence_is_rejected(): void
    {
        $outsource = Outsource::factory()->create(['status' => 'active']);
        $store = $this->makeStore(-6.2, 106.8, 150);
        $this->makeActiveAssignment($outsource, $store);

        $engine = $this->makeEngine();

        $this->expectException(\App\Domain\Attendance\Exceptions\OutsideGeofenceException::class);

        $engine->checkIn($outsource, $this->makeOperationData(-6.3, 106.9, $store->id));
    }

    public function test_outsource_without_assignment_cannot_check_in(): void
    {
        $outsource = Outsource::factory()->create(['status' => 'active']);
        $store = $this->makeStore(-6.2, 106.8, 150);

        $engine = $this->makeEngine();

        $this->expectException(\InvalidArgumentException::class);

        $engine->checkIn($outsource, $this->makeOperationData(-6.2001, 106.8001, $store->id));
    }

    public function test_inactive_outsource_cannot_check_in(): void
    {
        $outsource = Outsource::factory()->create(['status' => 'inactive']);
        $store = $this->makeStore(-6.2, 106.8, 150);
        $this->makeActiveAssignment($outsource, $store);

        $engine = $this->makeEngine();

        $this->expectException(\App\Exceptions\Domain\InactiveSubjectException::class);

        $engine->checkIn($outsource, $this->makeOperationData(-6.2001, 106.8001, $store->id));
    }

    public function test_inactive_store_blocks_outsource_check_in(): void
    {
        $outsource = Outsource::factory()->create(['status' => 'active']);
        $store = $this->makeStore(-6.2, 106.8, 150);
        $store->update(['status' => 'inactive']);
        $this->makeActiveAssignment($outsource, $store);

        $engine = $this->makeEngine();

        $this->expectException(\InvalidArgumentException::class);

        $engine->checkIn($outsource, $this->makeOperationData(-6.2001, 106.8001, $store->id));
    }

    public function test_outsource_check_in_creates_correct_event(): void
    {
        $outsource = Outsource::factory()->create(['status' => 'active']);
        $store = $this->makeStore(-6.2, 106.8, 150);
        $this->makeActiveAssignment($outsource, $store);

        $engine = $this->makeEngine();
        $result = $engine->checkIn($outsource, $this->makeOperationData(-6.2001, 106.8001, $store->id));

        $this->assertDatabaseHas('attendance_events', [
            'outsource_id' => $outsource->id,
            'employee_id' => null,
            'attendance_id' => $result->attendanceRecord->id,
            'event_type' => AttendanceEventType::CheckIn->value,
        ]);
    }

    public function test_outsource_check_out_creates_correct_event(): void
    {
        $outsource = Outsource::factory()->create(['status' => 'active']);
        $store = $this->makeStore(-6.2, 106.8, 150);
        $this->makeActiveAssignment($outsource, $store);

        $engine = $this->makeEngine();

        $checkInAt = CarbonImmutable::create(2026, 9, 12, 7, 59, 0, 'Asia/Jakarta');
        $checkOutAt = CarbonImmutable::create(2026, 9, 12, 17, 1, 0, 'Asia/Jakarta');

        $checkInResult = $engine->checkIn($outsource, $this->makeOperationData(-6.2001, 106.8001, $store->id, $checkInAt));
        $this->assertSame('incomplete', $checkInResult->attendanceRecord->status);

        $checkOutData = new AttendanceOperationData(
            employeeId: 0,
            latitude: -6.2001,
            longitude: 106.8001,
            accuracy: 12.5,
            deviceIdentifier: 'test-device',
            source: 'mobile',
            workLocationId: $store->id,
            occurredAt: $checkOutAt,
            eventType: AttendanceEventType::CheckOut,
        );

        $checkOutResult = $engine->checkOut($outsource, $checkOutData);
        $this->assertSame('present', $checkOutResult->attendanceRecord->status);

        $this->assertDatabaseHas('attendance_events', [
            'outsource_id' => $outsource->id,
            'employee_id' => null,
            'attendance_id' => $checkInResult->attendanceRecord->id,
            'event_type' => AttendanceEventType::CheckOut->value,
        ]);
    }

    public function test_outsource_closed_session_is_present_not_late(): void
    {
        $outsource = Outsource::factory()->create(['status' => 'active']);
        $store = $this->makeStore(-6.2, 106.8, 150);
        $this->makeActiveAssignment($outsource, $store);
        $engine = $this->makeEngine();

        // Wall times that would be "late" for a typical 08:00 employee shift.
        $checkInAt = CarbonImmutable::create(2026, 9, 12, 10, 30, 0, 'Asia/Jakarta');
        $checkOutAt = CarbonImmutable::create(2026, 9, 12, 14, 0, 0, 'Asia/Jakarta');

        $engine->checkIn($outsource, $this->makeOperationData(-6.2001, 106.8001, $store->id, $checkInAt));

        $out = $engine->checkOut($outsource, new AttendanceOperationData(
            employeeId: 0,
            latitude: -6.2001,
            longitude: 106.8001,
            accuracy: 12.5,
            deviceIdentifier: 'test-device',
            source: 'mobile',
            workLocationId: $store->id,
            occurredAt: $checkOutAt,
            eventType: AttendanceEventType::CheckOut,
        ));

        $this->assertSame('present', $out->attendanceRecord->status);
        $this->assertNotSame('late', $out->attendanceRecord->status);
        $this->assertNotSame('absent', $out->attendanceRecord->status);
    }

    public function test_outsource_rejects_second_session_same_clock_in_date(): void
    {
        $outsource = Outsource::factory()->create(['status' => 'active']);
        $store = $this->makeStore(-6.2, 106.8, 150);
        $this->makeActiveAssignment($outsource, $store);
        $engine = $this->makeEngine();

        $day = CarbonImmutable::create(2026, 9, 1, 8, 0, 0, 'Asia/Jakarta');
        $engine->checkIn($outsource, $this->makeOperationData(-6.2001, 106.8001, $store->id, $day));

        $engine->checkOut($outsource, new AttendanceOperationData(
            employeeId: 0,
            latitude: -6.2001,
            longitude: 106.8001,
            accuracy: 12.5,
            deviceIdentifier: 'test-device',
            source: 'mobile',
            workLocationId: $store->id,
            occurredAt: $day->addHours(10),
            eventType: AttendanceEventType::CheckOut,
        ));

        $this->expectException(\App\Domain\Attendance\Exceptions\AttendanceDayAlreadyCompletedException::class);

        $engine->checkIn($outsource, $this->makeOperationData(
            -6.2001,
            106.8001,
            $store->id,
            $day->addHours(12),
        ));
    }

    public function test_outsource_cross_midnight_keeps_attendance_date_from_clock_in(): void
    {
        $outsource = Outsource::factory()->create(['status' => 'active']);
        $store = $this->makeStore(-6.2, 106.8, 150);
        $this->makeActiveAssignment($outsource, $store);
        $engine = $this->makeEngine();

        $checkInAt = CarbonImmutable::create(2026, 9, 1, 20, 0, 0, 'Asia/Jakarta');
        $checkOutAt = CarbonImmutable::create(2026, 9, 2, 6, 0, 0, 'Asia/Jakarta');

        $in = $engine->checkIn($outsource, $this->makeOperationData(-6.2001, 106.8001, $store->id, $checkInAt));

        $out = $engine->checkOut($outsource, new AttendanceOperationData(
            employeeId: 0,
            latitude: -6.2001,
            longitude: 106.8001,
            accuracy: 12.5,
            deviceIdentifier: 'test-device',
            source: 'mobile',
            workLocationId: $store->id,
            occurredAt: $checkOutAt,
            eventType: AttendanceEventType::CheckOut,
        ));

        $this->assertSame('2026-09-01', $in->attendanceRecord->attendance_date->format('Y-m-d'));
        $this->assertSame('2026-09-01', $out->attendanceRecord->attendance_date->format('Y-m-d'));
        $this->assertSame(
            $checkOutAt->utc()->toIso8601String(),
            CarbonImmutable::parse($out->session->check_out_at)->utc()->toIso8601String()
        );
        $this->assertSame(1, AttendanceRecord::query()->count());
        $this->assertSame(1, AttendanceSession::query()->count());
    }

    public function test_outsource_next_day_clock_in_creates_new_record(): void
    {
        $outsource = Outsource::factory()->create(['status' => 'active']);
        $store = $this->makeStore(-6.2, 106.8, 150);
        $this->makeActiveAssignment($outsource, $store);
        $engine = $this->makeEngine();

        $monIn = CarbonImmutable::create(2026, 9, 1, 20, 0, 0, 'Asia/Jakarta');
        $tueOut = CarbonImmutable::create(2026, 9, 2, 6, 0, 0, 'Asia/Jakarta');
        $tueIn = CarbonImmutable::create(2026, 9, 2, 20, 0, 0, 'Asia/Jakarta');

        $engine->checkIn($outsource, $this->makeOperationData(-6.2001, 106.8001, $store->id, $monIn));
        $engine->checkOut($outsource, new AttendanceOperationData(
            employeeId: 0,
            latitude: -6.2001,
            longitude: 106.8001,
            accuracy: 12.5,
            deviceIdentifier: 'test-device',
            source: 'mobile',
            workLocationId: $store->id,
            occurredAt: $tueOut,
            eventType: AttendanceEventType::CheckOut,
        ));

        $second = $engine->checkIn($outsource, $this->makeOperationData(-6.2001, 106.8001, $store->id, $tueIn));

        $this->assertSame('2026-09-02', $second->attendanceRecord->attendance_date->format('Y-m-d'));
        $this->assertSame(2, AttendanceRecord::query()->count());
        $this->assertSame(2, AttendanceSession::query()->count());
    }

    public function test_outsource_session_expires_after_max_hours_without_checkout(): void
    {
        $outsource = Outsource::factory()->create(['status' => 'active']);
        $store = $this->makeStore(-6.2, 106.8, 150);
        $this->makeActiveAssignment($outsource, $store);
        $engine = $this->makeEngine();

        $checkInAt = CarbonImmutable::parse('2026-09-01T01:00:00Z'); // 08:00 Asia/Jakarta
        $tooLate = $checkInAt->addHours(20)->addMinute();

        $engine->checkIn($outsource, $this->makeOperationData(-6.2001, 106.8001, $store->id, $checkInAt));

        $this->expectException(\App\Domain\Attendance\Exceptions\AttendanceSessionExpiredException::class);

        try {
            $engine->checkOut($outsource, new AttendanceOperationData(
                employeeId: 0,
                latitude: -6.2001,
                longitude: 106.8001,
                accuracy: 12.5,
                deviceIdentifier: 'test-device',
                source: 'mobile',
                workLocationId: $store->id,
                occurredAt: $tooLate,
                eventType: AttendanceEventType::CheckOut,
            ));
        } finally {
            $session = AttendanceSession::query()->first();
            $this->assertNotNull($session);
            $this->assertSame('expired', $session->status);
            $this->assertSame('incomplete', AttendanceRecord::query()->first()?->status);
        }
    }
}
