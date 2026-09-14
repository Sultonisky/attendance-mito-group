<?php

namespace App\Domain\Leave\Rules;

use App\Domain\Leave\DTOs\LeaveDurationData;
use App\Domain\Leave\Exceptions\InvalidLeaveStateException;
use App\Models\Employee;
use App\Models\LeaveRequest;
use Carbon\CarbonImmutable;

/**
 * Pure calendar-date leave calculations and overlap detection.
 *
 * No HTTP, no persistence orchestration, and no balance decisions. The engine
 * and actions consume this rule to keep date handling deterministic.
 */
class LeaveDateRule
{
    /**
     * Inclusive calendar-day duration for a leave range.
     */
    public function duration(CarbonImmutable $start, CarbonImmutable $end): LeaveDurationData
    {
        $startDate = $start->startOfDay();
        $endDate = $end->startOfDay();

        if ($endDate->lessThan($startDate)) {
            throw new InvalidLeaveStateException('Leave end date must not be before start date.');
        }

        return new LeaveDurationData(
            startDate: $startDate,
            endDate: $endDate,
            totalDays: $startDate->diffInDays($endDate) + 1,
        );
    }

    /**
     * Whether two inclusive calendar-date ranges overlap.
     */
    public function overlaps(
        CarbonImmutable $startA,
        CarbonImmutable $endA,
        CarbonImmutable $startB,
        CarbonImmutable $endB,
    ): bool {
        return $startA->startOfDay()->lessThanOrEqualTo($endB->startOfDay())
            && $startB->startOfDay()->lessThanOrEqualTo($endA->startOfDay());
    }

    /**
     * Find an overlapping pending/approved leave request for the employee.
     */
    public function findOverlap(Employee $employee, CarbonImmutable $start, CarbonImmutable $end, ?int $ignoreId = null): ?LeaveRequest
    {
        return LeaveRequest::query()
            ->where('employee_id', $employee->id)
            ->whereIn('status', ['pending', 'approved'])
            ->when($ignoreId !== null, fn ($query) => $query->where('id', '!=', $ignoreId))
            ->whereDate('start_date', '<=', $end->toDateString())
            ->whereDate('end_date', '>=', $start->toDateString())
            ->orderBy('start_date')
            ->first();
    }
}
