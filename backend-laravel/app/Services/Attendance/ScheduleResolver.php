<?php

namespace App\Services\Attendance;

use App\Models\Employee;
use App\Models\ScheduleAssignment;
use App\Models\Shift;
use App\Models\WorkSchedule;
use Carbon\CarbonImmutable;

/**
 * Resolve the active work schedule and shift context for an employee on a
 * specific date.
 *
 * Schedule resolution is date-bound by effective_from / effective_to on
 * schedule_assignments. The returned schedule may contain multiple shifts;
 * the caller selects the shift matching the current time.
 */
class ScheduleResolver
{
    /**
     * Resolve the active schedule for the employee on the given date.
     *
     * @return array{schedule: WorkSchedule|null, shift: Shift|null, assignment: ScheduleAssignment|null}
     */
    public function resolveForDate(Employee $employee, CarbonImmutable $date): array
    {
        $assignment = ScheduleAssignment::query()
            ->where('employee_id', $employee->id)
            ->where('effective_from', '<=', $date->toDateString())
            ->where(function ($query) use ($date) {
                $query->whereNull('effective_to')
                    ->orWhere('effective_to', '>=', $date->toDateString());
            })
            ->with(['workSchedule.shifts' => function ($query) {
                $query->orderBy('start_time');
            }])
            ->orderByDesc('effective_from')
            ->first();

        if ($assignment === null) {
            return [
                'schedule' => null,
                'shift' => null,
                'assignment' => null,
            ];
        }

        $schedule = $assignment->workSchedule;

        return [
            'schedule' => $schedule,
            'shift' => $schedule->shifts->first() ?? null,
            'assignment' => $assignment,
        ];
    }

    /**
     * Resolve the active shift for the employee on the given date and time.
     *
     * Cross-midnight shifts are considered for the current time and, if no
     * match is found, for the previous day.
     *
     * @return array{schedule: WorkSchedule|null, shift: Shift|null, assignment: ScheduleAssignment|null}
     */
    public function resolveForDateTime(Employee $employee, CarbonImmutable $dateTime): array
    {
        $result = $this->resolveForDate($employee, $dateTime);

        if ($result['shift'] !== null) {
            return $result;
        }

        if ($dateTime->hour < 3) {
            $previousDay = $dateTime->subDay();
            $previousResult = $this->resolveForDate($employee, $previousDay);

            if ($previousResult['shift'] !== null && $previousResult['shift']->cross_midnight) {
                return $previousResult;
            }
        }

        return $result;
    }
}
