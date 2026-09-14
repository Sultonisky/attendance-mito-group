<?php

namespace Tests\Unit\Domain\Schedule;

use App\Domain\Schedule\Engines\ScheduleEngine;
use App\Domain\Schedule\Exceptions\AmbiguousScheduleAssignmentException;
use App\Domain\Schedule\Exceptions\InactiveScheduleException;
use App\Enums\RecordStatus;
use App\Models\Employee;
use App\Models\ScheduleAssignment;
use App\Models\Shift;
use App\Models\WorkSchedule;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ScheduleEngineTest extends TestCase
{
    use RefreshDatabase;

    private ScheduleEngine $engine;

    protected function setUp(): void
    {
        parent::setUp();
        $this->engine = new ScheduleEngine;
    }

    private function makeEmployee(): Employee
    {
        return Employee::factory()->create();
    }

    private function makeSchedule(array $overrides = []): WorkSchedule
    {
        return WorkSchedule::factory()->create($overrides);
    }

    private function makeShift(WorkSchedule $schedule, array $overrides = []): Shift
    {
        return Shift::factory()->forSchedule($schedule)->create($overrides);
    }

    private function makeAssignment(Employee $employee, WorkSchedule $schedule, array $overrides = []): ScheduleAssignment
    {
        return ScheduleAssignment::factory()->forEmployee($employee)->forSchedule($schedule)->create($overrides);
    }

    public function test_active_schedule_resolves_with_shifts(): void
    {
        $employee = $this->makeEmployee();
        $schedule = $this->makeSchedule();
        $this->makeShift($schedule, ['name' => 'Morning']);
        $this->makeShift($schedule, ['name' => 'Night']);
        $date = CarbonImmutable::parse('2026-06-15');

        $this->makeAssignment($employee, $schedule, [
            'effective_from' => '2026-01-01',
            'effective_to' => '2026-12-31',
        ]);

        $result = $this->engine->resolve($employee, $date);

        $this->assertSame($employee->id, $result->employeeId);
        $this->assertTrue($result->hasSchedule());
        $this->assertSame($schedule->id, $result->schedule->id);
        $this->assertSame(2, $result->schedule->shifts->count());
    }

    public function test_no_assignment_returns_no_schedule(): void
    {
        $employee = $this->makeEmployee();
        $date = CarbonImmutable::parse('2026-06-15');

        $result = $this->engine->resolve($employee, $date);

        $this->assertFalse($result->hasSchedule());
        $this->assertNull($result->schedule);
    }

    public function test_effective_from_respected_future_assignment_not_applied(): void
    {
        $employee = $this->makeEmployee();
        $schedule = $this->makeSchedule();
        $pastDate = CarbonImmutable::parse('2026-03-01');

        $this->makeAssignment($employee, $schedule, [
            'effective_from' => '2026-07-01',
            'effective_to' => '2026-12-31',
        ]);

        $result = $this->engine->resolve($employee, $pastDate);

        $this->assertFalse($result->hasSchedule());
    }

    public function test_effective_to_respected_expired_assignment_not_applied(): void
    {
        $employee = $this->makeEmployee();
        $schedule = $this->makeSchedule();
        $futureDate = CarbonImmutable::parse('2026-08-01');

        $this->makeAssignment($employee, $schedule, [
            'effective_from' => '2026-01-01',
            'effective_to' => '2026-06-30',
        ]);

        $result = $this->engine->resolve($employee, $futureDate);

        $this->assertFalse($result->hasSchedule());
    }

    public function test_open_ended_assignment_resolves(): void
    {
        $employee = $this->makeEmployee();
        $schedule = $this->makeSchedule();
        $date = CarbonImmutable::parse('2026-12-31');

        $this->makeAssignment($employee, $schedule, [
            'effective_from' => '2026-01-01',
            'effective_to' => null,
        ]);

        $result = $this->engine->resolve($employee, $date);

        $this->assertTrue($result->hasSchedule());
        $this->assertSame($schedule->id, $result->schedule->id);
    }

    public function test_inactive_schedule_throws_exception(): void
    {
        $employee = $this->makeEmployee();
        $schedule = $this->makeSchedule(['status' => RecordStatus::Inactive->value]);
        $date = CarbonImmutable::parse('2026-06-15');

        $this->makeAssignment($employee, $schedule, [
            'effective_from' => '2026-01-01',
            'effective_to' => '2026-12-31',
        ]);

        $this->expectException(InactiveScheduleException::class);
        $this->engine->resolve($employee, $date);
    }

    public function test_ambiguous_overlapping_assignments_throw_exception(): void
    {
        $employee = $this->makeEmployee();
        $scheduleA = $this->makeSchedule();
        $scheduleB = $this->makeSchedule();
        $date = CarbonImmutable::parse('2026-06-15');

        $this->makeAssignment($employee, $scheduleA, [
            'effective_from' => '2026-01-01',
            'effective_to' => '2026-12-31',
        ]);
        $this->makeAssignment($employee, $scheduleB, [
            'effective_from' => '2026-04-01',
            'effective_to' => '2026-10-01',
        ]);

        $this->expectException(AmbiguousScheduleAssignmentException::class);
        $this->engine->resolve($employee, $date);
    }

    public function test_historical_resolution_returns_correct_schedule(): void
    {
        $employee = $this->makeEmployee();
        $oldSchedule = $this->makeSchedule(['name' => 'Old Schedule']);
        $newSchedule = $this->makeSchedule(['name' => 'New Schedule']);
        $historicalDate = CarbonImmutable::parse('2026-03-15');
        $currentDate = CarbonImmutable::parse('2026-08-15');

        $this->makeAssignment($employee, $oldSchedule, [
            'effective_from' => '2026-01-01',
            'effective_to' => '2026-06-30',
        ]);
        $this->makeAssignment($employee, $newSchedule, [
            'effective_from' => '2026-07-01',
            'effective_to' => null,
        ]);

        $oldResult = $this->engine->resolve($employee, $historicalDate);
        $newResult = $this->engine->resolve($employee, $currentDate);

        $this->assertSame('Old Schedule', $oldResult->schedule->name);
        $this->assertSame('New Schedule', $newResult->schedule->name);
    }

    public function test_cross_midnight_shift_is_preserved(): void
    {
        $employee = $this->makeEmployee();
        $schedule = $this->makeSchedule();
        $this->makeShift($schedule, [
            'name' => 'Night Shift',
            'start_time' => '22:00:00',
            'end_time' => '06:00:00',
            'cross_midnight' => true,
        ]);
        $date = CarbonImmutable::parse('2026-06-15');

        $this->makeAssignment($employee, $schedule, [
            'effective_from' => '2026-01-01',
            'effective_to' => '2026-12-31',
        ]);

        $result = $this->engine->resolve($employee, $date);

        $this->assertTrue($result->hasSchedule());
        $shift = $result->schedule->shifts->first();
        $this->assertSame('22:00:00', $shift->start_time);
        $this->assertSame('06:00:00', $shift->end_time);
        $this->assertTrue($shift->cross_midnight);
    }

    public function test_future_assignment_does_not_override_current(): void
    {
        $employee = $this->makeEmployee();
        $currentSchedule = $this->makeSchedule(['name' => 'Current']);
        $futureSchedule = $this->makeSchedule(['name' => 'Future']);
        $currentDate = CarbonImmutable::parse('2026-09-15');

        $this->makeAssignment($employee, $currentSchedule, [
            'effective_from' => '2026-01-01',
            'effective_to' => '2026-09-30',
        ]);
        $this->makeAssignment($employee, $futureSchedule, [
            'effective_from' => '2026-10-01',
            'effective_to' => null,
        ]);

        $result = $this->engine->resolve($employee, $currentDate);

        $this->assertTrue($result->hasSchedule());
        $this->assertSame('Current', $result->schedule->name);
    }
}
