<?php

namespace App\Services\Outsource;

use App\Models\AttendanceRecord;
use App\Support\OutsourceAttendancePeriod;
use Illuminate\Database\Eloquent\Builder;

/**
 * Single source of the outsource "counted days" rule, shared by the outsource
 * history and the monthly recap so both always agree:
 *
 * - days belong to the period by attendance_date (the clock-in business day);
 * - only days with at least one clock-in are counted (open sessions included);
 * - only the first N such days (chronological, N = max attendance days, default 26)
 *   are counted; later days in the same period are ignored.
 */
class CountedOutsourceAttendance
{
    /**
     * Counted records for the period, oldest first, already limited to N days.
     *
     * @return Builder<AttendanceRecord>
     */
    public function query(int $outsourceId, OutsourceAttendancePeriod $period): Builder
    {
        return AttendanceRecord::query()
            ->where('outsource_id', $outsourceId)
            ->where('attendable_type', 'outsource')
            ->where('attendance_date', '>=', $period->startDate->toDateString())
            ->where('attendance_date', '<', $period->endDate->addDay()->toDateString())
            ->whereHas('sessions', fn ($query) => $query->whereNotNull('check_in_at'))
            ->orderBy('attendance_date')
            ->orderBy('id')
            ->limit(OutsourceAttendancePeriod::maxAttendanceDays());
    }

    /**
     * @return list<int>
     */
    public function countedIds(int $outsourceId, OutsourceAttendancePeriod $period): array
    {
        return $this->query($outsourceId, $period)
            ->pluck('attendance_records.id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }
}
