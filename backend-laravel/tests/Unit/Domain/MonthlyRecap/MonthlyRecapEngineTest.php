<?php

namespace Tests\Unit\Domain\MonthlyRecap;

use App\Domain\MonthlyRecap\DTOs\MonthlyRecapData;
use App\Domain\MonthlyRecap\Engines\MonthlyRecapEngine;
use App\Domain\MonthlyRecap\Exceptions\ScheduleEngineException;
use App\Domain\Schedule\DTOs\ScheduleResolutionData;
use App\Domain\Schedule\Engines\ScheduleEngine;
use App\Models\AttendanceRecord;
use App\Models\Employee;
use App\Models\Outsource;
use App\Models\OutsourceStoreAssignment;
use App\Models\Policy;
use App\Models\PolicyAssignment;
use App\Models\ScheduleAssignment;
use App\Models\Shift;
use App\Models\WorkLocation;
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
        return new MonthlyRecapEngine(app(ScheduleEngine::class));
    }

    public function test_empty_month_returns_zero_counts(): void
    {
        $employee = $this->makeEmployee();
        $this->makeScheduleAndPolicy($employee);

        $start = CarbonImmutable::create(2026, 9, 1);
        $end = CarbonImmutable::create(2026, 9, 30);
        $data = $this->engine()->generate($employee, $start, $end);

        $this->assertInstanceOf(MonthlyRecapData::class, $data);
        $this->assertSame('employee', $data->source);
        $this->assertSame($employee->id, $data->employeeId);
        $this->assertSame(30, $data->scheduledDays);
        $this->assertSame(0, $data->presentDays);
        $this->assertSame(0, $data->lateDays);
        $this->assertSame(0, $data->incompleteDays);
        $this->assertSame(30, $data->absentDays);
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

    public function test_attendance_only_ignores_leave_and_counts_as_absent_without_record(): void
    {
        $employee = $this->makeEmployee();
        $this->makeScheduleAndPolicy($employee);

        // Leave exists but attendance-only recap does not consume LeaveEngine.
        \App\Models\LeaveRequest::create([
            'employee_id' => $employee->id,
            'leave_type_id' => \App\Models\LeaveType::factory()->create()->id,
            'start_date' => '2026-09-01',
            'end_date' => '2026-09-05',
            'status' => 'approved',
        ]);

        $this->makeAttendance($employee, '2026-09-06', 'present');

        $start = CarbonImmutable::create(2026, 9, 1);
        $end = CarbonImmutable::create(2026, 9, 6);
        $data = $this->engine()->generate($employee, $start, $end);

        $this->assertSame(1, $data->presentDays);
        $this->assertSame(5, $data->absentDays);
        $this->assertCount(5, $data->details);
    }

    public function test_outsource_calendar_aggregation(): void
    {
        $outsource = Outsource::factory()->create(['status' => 'active']);
        $store = WorkLocation::factory()->create(['status' => 'active']);
        OutsourceStoreAssignment::factory()->forOutsource($outsource)->forStore($store)->create([
            'status' => 'active',
        ]);

        AttendanceRecord::factory()->forOutsource($outsource)->onDate('2026-09-01')->status('present')->create();
        AttendanceRecord::factory()->forOutsource($outsource)->onDate('2026-09-02')->status('incomplete')->create();

        $start = CarbonImmutable::create(2026, 9, 1);
        $end = CarbonImmutable::create(2026, 9, 3);
        $data = $this->engine()->generateForOutsource($outsource, $start, $end);

        $this->assertSame('outsource', $data->source);
        $this->assertSame($outsource->id, $data->outsourceId);
        $this->assertSame(3, $data->scheduledDays);
        $this->assertSame(1, $data->presentDays);
        $this->assertSame(1, $data->incompleteDays);
        $this->assertSame(1, $data->absentDays);
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
