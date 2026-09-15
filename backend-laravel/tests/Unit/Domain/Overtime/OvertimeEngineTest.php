<?php

namespace Tests\Unit\Domain\Overtime;

use App\Domain\Leave\Engines\LeaveEngine;
use App\Domain\Overtime\Engines\OvertimeEngine;
use App\Domain\Overtime\Exceptions\InactiveEmployeeException;
use App\Domain\Overtime\Exceptions\LeaveEngineException;
use App\Domain\Overtime\Rules\OvertimeEligibilityRule;
use App\Models\AttendanceRecord;
use App\Models\AttendanceSession;
use App\Models\Employee;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Models\Policy;
use App\Models\PolicyAssignment;
use App\Models\ScheduleAssignment;
use App\Models\Shift;
use App\Models\WorkSchedule;
use Carbon\CarbonImmutable;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OvertimeEngineTest extends TestCase
{
    use RefreshDatabase;

    private OvertimeEngine $engine;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->engine = new OvertimeEngine(
            app(OvertimeEligibilityRule::class),
            app(LeaveEngine::class),
        );
    }

    private function makeEmployee(array $overrides = []): Employee
    {
        return Employee::factory()->create($overrides);
    }

    private function makeScheduleAndPolicy(Employee $employee, ?string $endTime = '17:00:00', bool $crossMidnight = false): void
    {
        $schedule = WorkSchedule::factory()->create();
        Shift::factory()->forSchedule($schedule)->create([
            'start_time' => $crossMidnight ? '22:00:00' : '08:00:00',
            'end_time' => $endTime,
            'cross_midnight' => $crossMidnight,
        ]);
        ScheduleAssignment::factory()->forEmployee($employee)->forSchedule($schedule)->create([
            'effective_from' => '2026-01-01',
            'effective_to' => null,
        ]);

        $policy = Policy::factory()->create();
        PolicyAssignment::factory()->forEmployee($employee)->forPolicy($policy)->create([
            'effective_from' => '2026-01-01',
            'effective_to' => null,
        ]);
    }

    private function makeAttendance(Employee $employee, string $date, string $status = 'present', ?CarbonImmutable $checkIn = null, ?CarbonImmutable $checkOut = null): AttendanceRecord
    {
        $record = AttendanceRecord::factory()->forEmployee($employee)->onDate($date)->status($status)->create();

        if ($checkIn !== null && $checkOut !== null) {
            AttendanceSession::create([
                'attendance_record_id' => $record->id,
                'check_in_at' => $checkIn,
                'check_out_at' => $checkOut,
                'duration_minutes' => (int) $checkIn->diffInMinutes($checkOut),
                'status' => 'closed',
            ]);
        }

        return $record;
    }

    public function test_no_attendance_returns_no_overtime(): void
    {
        $employee = $this->makeEmployee();
        $this->makeScheduleAndPolicy($employee);

        $result = $this->engine->calculateForDate($employee, CarbonImmutable::parse('2026-09-10'));

        $this->assertFalse($result->eligible);
        $this->assertNull($result->potentialMinutes);
    }

    public function test_exact_scheduled_end_returns_zero_overtime(): void
    {
        $employee = $this->makeEmployee();
        $this->makeScheduleAndPolicy($employee, '17:00:00');

        $this->makeAttendance(
            $employee,
            '2026-09-10',
            'present',
            CarbonImmutable::parse('2026-09-10 08:00:00'),
            CarbonImmutable::parse('2026-09-10 17:00:00')
        );

        $result = $this->engine->calculateForDate($employee, CarbonImmutable::parse('2026-09-10'));

        $this->assertTrue($result->eligible);
        $this->assertSame(0, $result->potentialMinutes);
    }

    public function test_work_beyond_scheduled_end_generates_overtime(): void
    {
        $employee = $this->makeEmployee();
        $this->makeScheduleAndPolicy($employee, '17:00:00');

        $this->makeAttendance(
            $employee,
            '2026-09-10',
            'present',
            CarbonImmutable::parse('2026-09-10 08:00:00'),
            CarbonImmutable::parse('2026-09-10 18:30:00')
        );

        $result = $this->engine->calculateForDate($employee, CarbonImmutable::parse('2026-09-10'));

        $this->assertTrue($result->eligible);
        $this->assertSame(90, $result->potentialMinutes);
    }

    public function test_incomplete_attendance_returns_no_overtime(): void
    {
        $employee = $this->makeEmployee();
        $this->makeScheduleAndPolicy($employee);

        $this->makeAttendance($employee, '2026-09-10', 'incomplete');

        $result = $this->engine->calculateForDate($employee, CarbonImmutable::parse('2026-09-10'));

        $this->assertFalse($result->eligible);
        $this->assertNull($result->potentialMinutes);
    }

    public function test_off_day_returns_no_overtime(): void
    {
        $employee = $this->makeEmployee();
        $this->makeScheduleAndPolicy($employee);

        $this->makeAttendance($employee, '2026-09-10', 'off_day');

        $result = $this->engine->calculateForDate($employee, CarbonImmutable::parse('2026-09-10'));

        $this->assertFalse($result->eligible);
        $this->assertNull($result->potentialMinutes);
    }

    public function test_holiday_returns_no_overtime(): void
    {
        $employee = $this->makeEmployee();
        $this->makeScheduleAndPolicy($employee);

        $this->makeAttendance($employee, '2026-09-10', 'holiday');

        $result = $this->engine->calculateForDate($employee, CarbonImmutable::parse('2026-09-10'));

        $this->assertFalse($result->eligible);
        $this->assertNull($result->potentialMinutes);
    }

    public function test_approved_leave_returns_no_overtime(): void
    {
        $employee = $this->makeEmployee();
        $this->makeScheduleAndPolicy($employee);

        $this->makeAttendance(
            $employee,
            '2026-09-10',
            'present',
            CarbonImmutable::parse('2026-09-10 08:00:00'),
            CarbonImmutable::parse('2026-09-10 17:00:00')
        );

        $annual = LeaveType::factory()->annual()->create();
        LeaveRequest::create([
            'employee_id' => $employee->id,
            'leave_type_id' => $annual->id,
            'status' => 'approved',
            'start_date' => '2026-09-10',
            'end_date' => '2026-09-10',
        ]);

        $result = $this->engine->calculateForDate($employee, CarbonImmutable::parse('2026-09-10'));

        $this->assertFalse($result->eligible);
        $this->assertStringContainsString('leave', strtolower($result->reason));
    }

    public function test_multiple_sessions_uses_latest_checkout(): void
    {
        $employee = $this->makeEmployee();
        $this->makeScheduleAndPolicy($employee, '17:00:00');

        $record = AttendanceRecord::factory()->forEmployee($employee)->onDate('2026-09-10')->status('present')->create();

        AttendanceSession::create([
            'attendance_record_id' => $record->id,
            'check_in_at' => '2026-09-10 08:00:00',
            'check_out_at' => '2026-09-10 12:00:00',
            'duration_minutes' => 240,
            'status' => 'closed',
        ]);

        AttendanceSession::create([
            'attendance_record_id' => $record->id,
            'check_in_at' => '2026-09-10 13:00:00',
            'check_out_at' => '2026-09-10 19:00:00',
            'duration_minutes' => 360,
            'status' => 'closed',
        ]);

        $result = $this->engine->calculateForDate($employee, CarbonImmutable::parse('2026-09-10'));

        $this->assertTrue($result->eligible);
        $this->assertSame(120, $result->potentialMinutes);
    }

    public function test_cross_midnight_shift_calculates_overtime_correctly(): void
    {
        $employee = $this->makeEmployee();
        $schedule = WorkSchedule::factory()->create();
        Shift::factory()->forSchedule($schedule)->create([
            'start_time' => '22:00:00',
            'end_time' => '06:00:00',
            'cross_midnight' => true,
        ]);
        ScheduleAssignment::factory()->forEmployee($employee)->forSchedule($schedule)->create([
            'effective_from' => '2026-01-01',
            'effective_to' => null,
        ]);
        Policy::factory()->create();
        PolicyAssignment::factory()->forEmployee($employee)->forPolicy(Policy::first())->create([
            'effective_from' => '2026-01-01',
            'effective_to' => null,
        ]);

        $record = AttendanceRecord::factory()->forEmployee($employee)->onDate('2026-09-11')->status('present')->create();
        AttendanceSession::create([
            'attendance_record_id' => $record->id,
            'check_in_at' => '2026-09-11 22:05:00',
            'check_out_at' => '2026-09-12 07:00:00',
            'duration_minutes' => 535,
            'status' => 'closed',
        ]);

        $result = $this->engine->calculateForDate($employee, CarbonImmutable::parse('2026-09-11'));

        $this->assertTrue($result->eligible);
        $this->assertSame(60, $result->potentialMinutes);
    }

    public function test_inactive_employee_throws_exception(): void
    {
        $employee = $this->makeEmployee(['end_date' => '2026-01-01']);

        $this->expectException(InactiveEmployeeException::class);
        $this->engine->calculateForDate($employee, CarbonImmutable::parse('2026-09-10'));
    }

    public function test_no_schedule_returns_no_overtime(): void
    {
        $employee = $this->makeEmployee();
        Policy::factory()->create();
        PolicyAssignment::factory()->forEmployee($employee)->forPolicy(Policy::first())->create([
            'effective_from' => '2026-01-01',
            'effective_to' => null,
        ]);

        $result = $this->engine->calculateForDate($employee, CarbonImmutable::parse('2026-09-10'));

        $this->assertFalse($result->eligible);
        $this->assertNull($result->potentialMinutes);
    }

    public function test_duration_calculation_is_deterministic(): void
    {
        $start = CarbonImmutable::parse('2026-09-10 17:00:00');
        $end = CarbonImmutable::parse('2026-09-10 19:30:00');

        $duration = $this->engine->calculateDuration($start, $end);

        $this->assertSame(150, $duration->minutes);
        $this->assertSame($start, $duration->scheduledEnd);
        $this->assertSame($end, $duration->latestCheckOut);
    }

    public function test_duration_returns_zero_when_checkout_before_scheduled_end(): void
    {
        $start = CarbonImmutable::parse('2026-09-10 17:00:00');
        $end = CarbonImmutable::parse('2026-09-10 16:30:00');

        $duration = $this->engine->calculateDuration($start, $end);

        $this->assertSame(0, $duration->minutes);
    }

    public function test_leave_engine_failure_throws_domain_exception(): void
    {
        $employee = $this->makeEmployee();
        $this->makeScheduleAndPolicy($employee);

        $failingLeaveEngine = \Mockery::mock(LeaveEngine::class);
        $failingLeaveEngine->shouldReceive('resolveForDate')
            ->andThrow(new \RuntimeException('database connection down'));

        $engine = new OvertimeEngine(
            app(OvertimeEligibilityRule::class),
            $failingLeaveEngine,
        );

        $this->expectException(LeaveEngineException::class);
        $engine->calculateForDate($employee, CarbonImmutable::parse('2026-09-10'));
    }

    public function test_pending_leave_does_not_block_overtime(): void
    {
        $employee = $this->makeEmployee();
        $this->makeScheduleAndPolicy($employee, '17:00:00');

        $this->makeAttendance(
            $employee,
            '2026-09-10',
            'present',
            CarbonImmutable::parse('2026-09-10 08:00:00'),
            CarbonImmutable::parse('2026-09-10 18:30:00')
        );

        $annual = LeaveType::factory()->annual()->create();
        LeaveRequest::create([
            'employee_id' => $employee->id,
            'leave_type_id' => $annual->id,
            'status' => 'pending',
            'start_date' => '2026-09-10',
            'end_date' => '2026-09-10',
        ]);

        $result = $this->engine->calculateForDate($employee, CarbonImmutable::parse('2026-09-10'));

        $this->assertTrue($result->eligible);
        $this->assertSame(90, $result->potentialMinutes);
    }

    public function test_rejected_leave_does_not_block_overtime(): void
    {
        $employee = $this->makeEmployee();
        $this->makeScheduleAndPolicy($employee, '17:00:00');

        $this->makeAttendance(
            $employee,
            '2026-09-10',
            'present',
            CarbonImmutable::parse('2026-09-10 08:00:00'),
            CarbonImmutable::parse('2026-09-10 18:30:00')
        );

        $annual = LeaveType::factory()->annual()->create();
        LeaveRequest::create([
            'employee_id' => $employee->id,
            'leave_type_id' => $annual->id,
            'status' => 'rejected',
            'start_date' => '2026-09-10',
            'end_date' => '2026-09-10',
        ]);

        $result = $this->engine->calculateForDate($employee, CarbonImmutable::parse('2026-09-10'));

        $this->assertTrue($result->eligible);
        $this->assertSame(90, $result->potentialMinutes);
    }
}
