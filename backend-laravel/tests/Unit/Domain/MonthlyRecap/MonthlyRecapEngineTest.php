<?php

namespace Tests\Unit\Domain\MonthlyRecap;

use App\Domain\Leave\Engines\LeaveEngine;
use App\Domain\MonthlyRecap\DTOs\MonthlyRecapData;
use App\Domain\MonthlyRecap\Engines\MonthlyRecapEngine;
use App\Domain\MonthlyRecap\Exceptions\ScheduleEngineException;
use App\Domain\Schedule\DTOs\ScheduleResolutionData;
use App\Domain\Schedule\Engines\ScheduleEngine;
use App\Enums\ApprovalStatus;
use App\Enums\PenaltyStatus;
use App\Enums\PermissionRequestType;
use App\Models\AttendanceRecord;
use App\Models\AttendanceSession;
use App\Models\Employee;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Models\OvertimeRecord;
use App\Models\PenaltyRecord;
use App\Models\PenaltyRule;
use App\Models\PermissionRequest;
use App\Models\Policy;
use App\Models\PolicyAssignment;
use App\Models\ScheduleAssignment;
use App\Models\Shift;
use App\Models\WorkSchedule;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MonthlyRecapEngineTest extends TestCase
{
    use RefreshDatabase;

    private function makeEmployee(array $overrides = []): Employee
    {
        return Employee::factory()->create($overrides);
    }

    private function makeScheduleAndPolicy(Employee $employee): void
    {
        $schedule = WorkSchedule::factory()->create();
        Shift::factory()->forSchedule($schedule)->create([
            'start_time' => '08:00:00',
            'end_time' => '17:00:00',
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

    private function makeAttendance(Employee $employee, string $date, string $status): AttendanceRecord
    {
        return AttendanceRecord::factory()->forEmployee($employee)->onDate($date)->status($status)->create();
    }

    private function engine(): MonthlyRecapEngine
    {
        return new MonthlyRecapEngine(app(LeaveEngine::class), app(ScheduleEngine::class));
    }

    public function test_empty_month_returns_zero_counts(): void
    {
        $employee = $this->makeEmployee();
        $this->makeScheduleAndPolicy($employee);

        $start = CarbonImmutable::create(2026, 9, 1);
        $end = CarbonImmutable::create(2026, 9, 30);
        $data = $this->engine()->generate($employee, $start, $end);

        $this->assertInstanceOf(MonthlyRecapData::class, $data);
        $this->assertSame($employee->id, $data->employeeId);
        $this->assertSame(30, $data->scheduledDays);
        $this->assertSame(0, $data->presentDays);
        $this->assertSame(0, $data->lateDays);
        $this->assertSame(0, $data->incompleteDays);
        $this->assertSame(30, $data->absentDays);
        $this->assertSame(0, $data->leaveDays);
        $this->assertSame(0, $data->overtimeApprovedMinutes);
        $this->assertSame(0, $data->penaltyCount);
        $this->assertSame(0.0, $data->penaltyPoints);
    }

    public function test_month_boundary_leap_year_february(): void
    {
        $employee = $this->makeEmployee();
        $this->makeScheduleAndPolicy($employee);

        $start = CarbonImmutable::create(2028, 2, 1);
        $end = CarbonImmutable::create(2028, 2, 29);
        $data = $this->engine()->generate($employee, $start, $end);

        $this->assertSame(29, $data->periodEnd->day);
        $this->assertSame(29, $data->scheduledDays);
    }

    public function test_attendance_aggregation_by_status(): void
    {
        $employee = $this->makeEmployee();
        $this->makeScheduleAndPolicy($employee);

        $this->makeAttendance($employee, '2026-09-01', 'present');
        $this->makeAttendance($employee, '2026-09-02', 'late');
        $this->makeAttendance($employee, '2026-09-03', 'incomplete');

        $start = CarbonImmutable::create(2026, 9, 1);
        $end = CarbonImmutable::create(2026, 9, 30);
        $data = $this->engine()->generate($employee, $start, $end);

        $this->assertSame(1, $data->presentDays);
        $this->assertSame(1, $data->lateDays);
        $this->assertSame(1, $data->incompleteDays);
    }

    public function test_absent_days_calculation(): void
    {
        $employee = $this->makeEmployee();
        $this->makeScheduleAndPolicy($employee);

        $this->makeAttendance($employee, '2026-09-01', 'present');

        $start = CarbonImmutable::create(2026, 9, 1);
        $end = CarbonImmutable::create(2026, 9, 2);
        $data = $this->engine()->generate($employee, $start, $end);

        $this->assertSame(2, $data->scheduledDays);
        $this->assertSame(1, $data->presentDays);
        $this->assertSame(1, $data->absentDays);
    }

    public function test_leave_aggregation(): void
    {
        $employee = $this->makeEmployee();
        $this->makeScheduleAndPolicy($employee);

        LeaveRequest::create([
            'employee_id' => $employee->id,
            'leave_type_id' => LeaveType::factory()->create()->id,
            'start_date' => '2026-09-01',
            'end_date' => '2026-09-05',
            'status' => 'approved',
        ]);

        $this->makeAttendance($employee, '2026-09-06', 'present');

        $start = CarbonImmutable::create(2026, 9, 1);
        $end = CarbonImmutable::create(2026, 9, 6);
        $data = $this->engine()->generate($employee, $start, $end);

        $this->assertSame(5, $data->leaveDays);
        $this->assertSame(1, $data->presentDays);
    }

    public function test_overtime_aggregation(): void
    {
        $employee = $this->makeEmployee();
        $this->makeScheduleAndPolicy($employee);

        $record = AttendanceRecord::create([
            'employee_id' => $employee->id,
            'attendance_date' => '2026-09-01',
            'status' => 'present',
        ]);
        AttendanceSession::create([
            'attendance_record_id' => $record->id,
            'check_in_at' => '2026-09-01 08:00:00',
            'check_out_at' => '2026-09-01 19:00:00',
            'duration_minutes' => 660,
            'status' => 'closed',
        ]);

        OvertimeRecord::create([
            'employee_id' => $employee->id,
            'attendance_id' => $record->id,
            'date' => '2026-09-01',
            'potential_minutes' => 120,
            'requested_minutes' => 120,
            'approved_minutes' => 120,
            'actual_minutes' => 120,
            'status' => 'approved',
        ]);

        $start = CarbonImmutable::create(2026, 9, 1);
        $end = CarbonImmutable::create(2026, 9, 1);
        $data = $this->engine()->generate($employee, $start, $end);

        $this->assertSame(120, $data->overtimeApprovedMinutes);
        $this->assertSame(120, $data->overtimePotentialMinutes);
    }

    public function test_penalty_aggregation_excludes_voided(): void
    {
        $employee = $this->makeEmployee();
        $this->makeScheduleAndPolicy($employee);

        $rule = PenaltyRule::create([
            'code' => 'late_arrival',
            'name' => 'Late Arrival',
            'points' => 2,
        ]);

        PenaltyRecord::create([
            'employee_id' => $employee->id,
            'penalty_rule_id' => $rule->id,
            'original_points' => 2,
            'final_points' => 2,
            'status' => PenaltyStatus::Applied->value,
            'occurred_at' => '2026-09-01 09:00:00',
        ]);

        PenaltyRecord::create([
            'employee_id' => $employee->id,
            'penalty_rule_id' => $rule->id,
            'original_points' => 1,
            'final_points' => 0,
            'status' => PenaltyStatus::Voided->value,
            'occurred_at' => '2026-09-02 09:00:00',
        ]);

        $start = CarbonImmutable::create(2026, 9, 1);
        $end = CarbonImmutable::create(2026, 9, 2);
        $data = $this->engine()->generate($employee, $start, $end);

        $this->assertSame(2.0, $data->penaltyPoints);
        $this->assertSame(1, $data->penaltyCount);
    }

    public function test_business_trip_excluded_from_absent(): void
    {
        $employee = $this->makeEmployee();
        $this->makeScheduleAndPolicy($employee);

        PermissionRequest::create([
            'employee_id' => $employee->id,
            'permission_type' => PermissionRequestType::Business->value,
            'status' => ApprovalStatus::Approved->value,
            'start_at' => '2026-09-01 00:00:00',
            'end_at' => '2026-09-01 23:59:59',
            'reason' => 'Client visit',
        ]);

        $this->makeAttendance($employee, '2026-09-02', 'present');

        $start = CarbonImmutable::create(2026, 9, 1);
        $end = CarbonImmutable::create(2026, 9, 2);
        $data = $this->engine()->generate($employee, $start, $end);

        $this->assertSame(2, $data->scheduledDays);
        $this->assertSame(1, $data->businessTripDays);
        $this->assertSame(1, $data->presentDays);
        $this->assertSame(0, $data->absentDays);
    }

    public function test_invalid_period_throws_exception(): void
    {
        $employee = $this->makeEmployee();

        $this->expectException(\InvalidArgumentException::class);
        $this->engine()->generate($employee, CarbonImmutable::create(2026, 9, 1), CarbonImmutable::create(2026, 8, 1));
    }

    public function test_schedule_engine_failure_propagates(): void
    {
        $employee = $this->makeEmployee();

        app()->bind(ScheduleEngine::class, function () {
            return new class extends ScheduleEngine
            {
                public function resolve($employee, CarbonImmutable $date): ScheduleResolutionData
                {
                    throw new \RuntimeException('schedule down');
                }
            };
        });

        $this->expectException(ScheduleEngineException::class);
        $this->engine()->generate($employee, CarbonImmutable::create(2026, 9, 1), CarbonImmutable::create(2026, 9, 1));
    }
}
