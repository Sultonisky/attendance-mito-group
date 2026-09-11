<?php

namespace App\Domain\Schedule\Engines;

use App\Domain\Schedule\DTOs\ScheduleResolutionData;
use App\Domain\Schedule\Exceptions\AmbiguousScheduleAssignmentException;
use App\Domain\Schedule\Exceptions\InactiveScheduleException;
use App\Enums\RecordStatus;
use App\Models\Employee;
use App\Models\ScheduleAssignment;
use Carbon\CarbonImmutable;

/**
 * Resolves the applicable work schedule (and its shifts) for an employee on a
 * given date.
 *
 * Resolution rules:
 *
 * 1. Find schedule assignments where the date falls within [effective_from, effective_to].
 * 2. If zero assignments are found, return an explicit no-schedule result.
 * 3. If more than one assignment overlaps, throw AmbiguousScheduleAssignmentException.
 * 4. If the assigned work schedule is not active, throw InactiveScheduleException.
 * 5. Otherwise, return the active work schedule with its shifts.
 */
class ScheduleEngine
{
    /**
     * Resolve the schedule applicable to the employee on the given date.
     */
    public function resolve(Employee $employee, CarbonImmutable $date): ScheduleResolutionData
    {
        $assignments = ScheduleAssignment::where('employee_id', $employee->id)
            ->where('effective_from', '<=', $date->toDateString())
            ->where(function ($query) use ($date): void {
                $query->whereNull('effective_to')
                    ->orWhere('effective_to', '>=', $date->toDateString());
            })
            ->with('workSchedule.shifts')
            ->get();

        if ($assignments->isEmpty()) {
            return new ScheduleResolutionData(
                employeeId: $employee->id,
                date: $date,
                schedule: null,
            );
        }

        if ($assignments->count() > 1) {
            throw new AmbiguousScheduleAssignmentException(
                'Multiple schedule assignments overlap for employee '.$employee->id.' on '.$date->toDateString().'.'
            );
        }

        $assignment = $assignments->first();
        $schedule = $assignment->workSchedule;

        if ($schedule === null || $schedule->status !== RecordStatus::Active->value) {
            throw new InactiveScheduleException(
                'Schedule assignment for employee '.$employee->id.' on '.$date->toDateString().' references an inactive schedule.'
            );
        }

        return new ScheduleResolutionData(
            employeeId: $employee->id,
            date: $date,
            schedule: $schedule,
        );
    }
}
