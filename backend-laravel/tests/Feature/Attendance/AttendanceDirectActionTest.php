<?php

namespace Tests\Feature\Attendance;

use App\Actions\Attendance\CheckInEmployee;
use App\Actions\Attendance\CheckOutEmployee;
use App\Actions\Audit\RecordAuditAction;
use App\Actions\Face\VerifyFaceAction;
use App\Domain\Attendance\Engines\AttendanceEngine;
use App\Domain\Attendance\Rules\AttendanceStateRule;
use App\Domain\Attendance\Rules\EarlyCheckoutRule;
use App\Domain\Attendance\Rules\GeofenceRule;
use App\Domain\Attendance\Rules\GpsValidationRule;
use App\Domain\Attendance\Rules\LateDetectionRule;
use App\Domain\Policy\Engines\PolicyEngine;
use App\Domain\Schedule\Engines\ScheduleEngine;
use App\Enums\AttendanceEventType;
use App\Enums\AttendanceSessionStatus;
use App\Enums\AttendanceStatus;
use App\Models\AttendanceRecord;
use App\Models\Employee;
use App\Models\ScheduleAssignment;
use App\Models\Shift;
use App\Models\User;
use App\Models\WorkSchedule;
use App\Services\Integration\FastApiService;
use Carbon\CarbonImmutable;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class AttendanceDirectActionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);
    }

    private function makeEmployee(array $overrides = []): Employee
    {
        return Employee::factory()->create(array_merge([
            'employment_status' => 'permanent',
        ], $overrides));
    }

    private function makeWorkSchedule(array $overrides = []): WorkSchedule
    {
        return WorkSchedule::factory()->create($overrides);
    }

    private function makeShift(WorkSchedule $schedule, array $overrides = []): Shift
    {
        return Shift::factory()->create(array_merge([
            'work_schedule_id' => $schedule->id,
        ], $overrides));
    }

    private function makeScheduleAssignment(Employee $employee, WorkSchedule $schedule, array $overrides = []): ScheduleAssignment
    {
        return ScheduleAssignment::factory()->create(array_merge([
            'employee_id' => $employee->id,
            'work_schedule_id' => $schedule->id,
        ], $overrides));
    }

    private function userForEmployee(Employee $employee, array $overrides = []): User
    {
        return User::firstOrCreate(
            ['email' => $employee->email],
            array_merge([
                'name' => $employee->full_name,
                'password' => Hash::make('password'),
            ], $overrides)
        );
    }

    private function makeEngine(): AttendanceEngine
    {
        return new AttendanceEngine(
            new PolicyEngine,
            new ScheduleEngine,
            new GpsValidationRule,
            new GeofenceRule,
            new LateDetectionRule,
            new EarlyCheckoutRule,
            new AttendanceStateRule,
            new \App\Domain\Attendance\Services\OutsourceSessionExpiry,
            new \App\Services\Outsource\ResolveOutsourceAllowedPins,
        );
    }

    private function makeAuditAction(): RecordAuditAction
    {
        return new RecordAuditAction;
    }

    public function test_direct_check_in_then_check_out_creates_and_closes_session(): void
    {
        $employee = $this->makeEmployee();
        $schedule = $this->makeWorkSchedule();
        $shift = $this->makeShift($schedule, [
            'start_time' => '08:00:00',
            'end_time' => '17:00:00',
        ]);
        $this->makeScheduleAssignment($employee, $schedule);

        $engine = $this->makeEngine();
        $audit = $this->makeAuditAction();
        $fastApi = new FastApiService;
        $verifyFace = new VerifyFaceAction($fastApi);
        $checkIn = new CheckInEmployee($engine, $audit, $verifyFace);
        $checkOut = new CheckOutEmployee($engine, $audit, $verifyFace);

        $checkInResult = $checkIn->execute($employee, CarbonImmutable::create(2026, 9, 12, 7, 59, 0, 'Asia/Jakarta'), [
            'latitude' => -6.2,
            'longitude' => 106.8,
        ]);

        $this->assertNull($checkInResult['error']);
        $this->assertEquals(AttendanceStatus::Incomplete->value, $checkInResult['record']->status);

        $recordId = $checkInResult['record']->id;
        $record = AttendanceRecord::find($recordId);
        $this->assertNotNull($record, 'AttendanceRecord should exist after direct check-in');
        $this->assertDatabaseHas('attendance_records', [
            'id' => $recordId,
            'employee_id' => $employee->id,
            'attendance_date' => $record->attendance_date,
        ]);

        $checkOutResult = $checkOut->execute($employee, CarbonImmutable::create(2026, 9, 12, 17, 1, 0, 'Asia/Jakarta'), [
            'latitude' => -6.2,
            'longitude' => 106.8,
        ]);

        $this->assertNull($checkOutResult['error']);
        $this->assertNotNull($checkOutResult['record']);
        $this->assertNotNull($checkOutResult['session']);
        $this->assertEquals(AttendanceSessionStatus::Closed->value, $checkOutResult['session']->status);
        $this->assertNotNull($checkOutResult['session']->check_out_at);
        $this->assertNotNull($checkOutResult['session']->duration_minutes);

        $this->assertDatabaseHas('attendance_events', [
            'employee_id' => $employee->id,
            'event_type' => AttendanceEventType::CheckOut->value,
        ]);
    }
}
