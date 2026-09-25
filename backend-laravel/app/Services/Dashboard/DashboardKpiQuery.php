<?php

namespace App\Services\Dashboard;

use App\Models\Employee;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class DashboardKpiQuery
{
    // ──────────────────────────────────────────────────────────────────────────
    // Public entry-points
    // ──────────────────────────────────────────────────────────────────────────

    public function forToday(User $user, string $source = 'employee'): array
    {
        $today = CarbonImmutable::now()->toDateString();

        if ($source === 'outsource') {
            $result = $this->aggregateOutsourceForDate($today);
        } else {
            $result = $this->aggregateForDate($today, $this->employeeScope($user));
        }

        return ['date' => $today, ...$result];
    }

    /**
     * Staff rows for the dashboard table (today, eligible scheduled employees).
     *
     * @return list<array{id: int, code: string, name: string, email: string|null, location: string, status: string}>
     */
    public function staffToday(User $user, string $source = 'employee'): array
    {
        $today = CarbonImmutable::now()->toDateString();

        if ($source === 'outsource') {
            return $this->outsourceStaffToday($today);
        }

        return $this->employeeStaffToday($today, $user);
    }

    /**
     * Daily attendance rate points for the dashboard chart.
     *
     * @return list<array{date: string, present: int, absent: int, late: int, on_leave: int, rate: int}>
     */
    public function attendanceTrend(User $user, string $from, string $to, string $source = 'employee'): array
    {
        $fromDate = CarbonImmutable::parse($from)->startOfDay();
        $toDate   = CarbonImmutable::parse($to)->startOfDay();

        if ($fromDate->greaterThan($toDate)) {
            [$fromDate, $toDate] = [$toDate, $fromDate];
        }

        // Cap to 120 days to keep dashboard queries bounded.
        if ($fromDate->diffInDays($toDate) > 120) {
            $fromDate = $toDate->subDays(120);
        }

        if ($source === 'outsource') {
            return $this->outsourceTrend($fromDate, $toDate);
        }

        return $this->employeeTrend($user, $fromDate, $toDate);
    }

    // ──────────────────────────────────────────────────────────────────────────
    // Employee trend (original logic, extracted)
    // ──────────────────────────────────────────────────────────────────────────

    /**
     * @return list<array{date: string, present: int, absent: int, late: int, on_leave: int, rate: int}>
     */
    private function employeeTrend(User $user, CarbonImmutable $fromDate, CarbonImmutable $toDate): array
    {
        $employeeId  = $this->employeeScope($user);
        $eligibleIds = $this->eligibleEmployeeIds($toDate->toDateString(), $employeeId);

        if ($eligibleIds->isEmpty()) {
            return $this->emptyTrendRange($fromDate, $toDate);
        }

        $attendance = DB::table('attendance_records')
            ->select(['attendance_date', 'employee_id', 'status'])
            ->whereIn('employee_id', $eligibleIds)
            ->whereNotNull('employee_id')
            ->whereDate('attendance_date', '>=', $fromDate->toDateString())
            ->whereDate('attendance_date', '<=', $toDate->toDateString())
            ->get()
            ->groupBy(static fn ($row) => CarbonImmutable::parse($row->attendance_date)->toDateString());

        $leaves = DB::table('leave_requests')
            ->select(['employee_id', 'start_date', 'end_date'])
            ->whereIn('employee_id', $eligibleIds)
            ->where('status', 'approved')
            ->whereDate('start_date', '<=', $toDate->toDateString())
            ->whereDate('end_date', '>=', $fromDate->toDateString())
            ->get();

        return $this->buildTrendPoints($fromDate, $toDate, $eligibleIds, $attendance, $leaves);
    }

    // ──────────────────────────────────────────────────────────────────────────
    // Outsource trend
    // ──────────────────────────────────────────────────────────────────────────

    /**
     * @return list<array{date: string, present: int, absent: int, late: int, on_leave: int, rate: int}>
     */
    private function outsourceTrend(CarbonImmutable $fromDate, CarbonImmutable $toDate): array
    {
        $eligibleIds = $this->eligibleOutsourceIds();

        if ($eligibleIds->isEmpty()) {
            return $this->emptyTrendRange($fromDate, $toDate);
        }

        $attendance = DB::table('attendance_records')
            ->select(['attendance_date', 'outsource_id', 'status'])
            ->whereIn('outsource_id', $eligibleIds)
            ->whereNotNull('outsource_id')
            ->where('attendable_type', 'outsource')
            ->whereDate('attendance_date', '>=', $fromDate->toDateString())
            ->whereDate('attendance_date', '<=', $toDate->toDateString())
            ->get()
            ->groupBy(static fn ($row) => CarbonImmutable::parse($row->attendance_date)->toDateString());

        $points = [];
        for ($date = $fromDate; $date->lessThanOrEqualTo($toDate); $date = $date->addDay()) {
            $key         = $date->toDateString();
            $dayRows     = $attendance->get($key, collect());
            $present     = 0;
            $late        = 0;
            $absent      = 0;
            $seen        = [];

            foreach ($dayRows as $row) {
                $oid = (int) $row->outsource_id;
                if (isset($seen[$oid])) {
                    continue;
                }
                $seen[$oid] = true;

                if (in_array($row->status, ['present', 'incomplete'], true)) {
                    $present++;
                } elseif ($row->status === 'late') {
                    $late++;
                } elseif ($row->status === 'absent') {
                    $absent++;
                }
            }

            foreach ($eligibleIds as $oid) {
                if (!isset($seen[$oid])) {
                    $absent++;
                }
            }

            $total = $present + $late + $absent;
            $rate  = $total === 0 ? 0 : (int) round((($present + $late) / $total) * 100);

            $points[] = [
                'date'     => $key,
                'present'  => $present,
                'absent'   => $absent,
                'late'     => $late,
                'on_leave' => 0, // outsource workers have no leave system
                'rate'     => $rate,
            ];
        }

        return $points;
    }

    // ──────────────────────────────────────────────────────────────────────────
    // KPI aggregation
    // ──────────────────────────────────────────────────────────────────────────

    /**
     * @return array{present: int, absent: int, late: int, on_leave: int}
     */
    private function aggregateForDate(string $today, ?int $employeeId): array
    {
        $sub = $this->eligibleEmployeesBase($today)
            ->select([
                'e.id',
                DB::raw('MAX(CASE WHEN lr.id IS NOT NULL THEN 1 ELSE 0 END) as has_leave'),
                DB::raw("MAX(CASE WHEN ar.status IN ('present', 'incomplete') THEN 1 ELSE 0 END) as is_present"),
                DB::raw("MAX(CASE WHEN ar.status = 'late' THEN 1 ELSE 0 END) as is_late"),
                DB::raw("MAX(CASE WHEN ar.status = 'absent' OR ar.id IS NULL THEN 1 ELSE 0 END) as is_absent"),
            ])
            ->groupBy('e.id');

        $query = DB::table(DB::raw("({$sub->toSql()}) as classified"))
            ->mergeBindings($sub)
            ->select([
                DB::raw('COUNT(*) as total_eligible'),
                DB::raw('SUM(has_leave) as on_leave'),
                DB::raw('SUM(CASE WHEN has_leave = 0 AND is_present = 1 THEN 1 ELSE 0 END) as present'),
                DB::raw('SUM(CASE WHEN has_leave = 0 AND is_late = 1 THEN 1 ELSE 0 END) as late'),
                DB::raw('SUM(CASE WHEN has_leave = 0 AND is_absent = 1 THEN 1 ELSE 0 END) as absent'),
            ]);

        if ($employeeId !== null) {
            $query->where('classified.id', $employeeId);
        }

        $result = $query->first();

        return [
            'present'     => (int) ($result->present ?? 0),
            'absent'      => (int) ($result->absent ?? 0),
            'late'        => (int) ($result->late ?? 0),
            'on_leave'    => (int) ($result->on_leave ?? 0),
            'incomplete'  => 0,
        ];
    }

    /**
     * Outsource KPIs: Present / Incomplete / Absent only (no late / on-leave).
     *
     * @return array{present: int, absent: int, late: int, on_leave: int, incomplete: int}
     */
    private function aggregateOutsourceForDate(string $today): array
    {
        $eligibleIds = $this->eligibleOutsourceIds();

        if ($eligibleIds->isEmpty()) {
            return [
                'present' => 0,
                'absent' => 0,
                'late' => 0,
                'on_leave' => 0,
                'incomplete' => 0,
            ];
        }

        $rows = DB::table('attendance_records')
            ->select(['outsource_id', 'status'])
            ->whereIn('outsource_id', $eligibleIds)
            ->whereNotNull('outsource_id')
            ->where('attendable_type', 'outsource')
            ->whereDate('attendance_date', '=', $today)
            ->get()
            ->keyBy('outsource_id');

        $present = 0;
        $incomplete = 0;
        $absent = 0;

        foreach ($eligibleIds as $oid) {
            $row = $rows->get($oid);
            if (! $row) {
                $absent++;
                continue;
            }

            if ($row->status === 'incomplete') {
                $incomplete++;
            } elseif (in_array($row->status, ['present', 'late'], true)) {
                // Late still means checked in; outsource KPI has no Late card.
                $present++;
            } else {
                $absent++;
            }
        }

        return [
            'present' => $present,
            'absent' => $absent,
            'late' => 0,
            'on_leave' => 0,
            'incomplete' => $incomplete,
        ];
    }

    // ──────────────────────────────────────────────────────────────────────────
    // Staff-today helpers
    // ──────────────────────────────────────────────────────────────────────────

    /**
     * @return list<array{id: int, code: string, name: string, email: string|null, location: string, status: string}>
     */
    private function employeeStaffToday(string $today, User $user): array
    {
        $employeeId = $this->employeeScope($user);

        $query = $this->eligibleEmployeesBase($today)
            ->select([
                'e.id',
                'e.employee_code',
                'e.full_name as name',
                'e.email',
                DB::raw("COALESCE(NULLIF(TRIM(e.branch), ''), 'MITO HQ') as location"),
                DB::raw("CASE
                    WHEN MAX(CASE WHEN lr.id IS NOT NULL THEN 1 ELSE 0 END) = 1 THEN 'On leave'
                    WHEN MAX(CASE WHEN ar.status = 'late' THEN 1 ELSE 0 END) = 1 THEN 'Late'
                    WHEN MAX(CASE WHEN ar.status IN ('present', 'incomplete') THEN 1 ELSE 0 END) = 1 THEN 'Present'
                    ELSE 'Absent'
                END as status"),
            ])
            ->groupBy('e.id', 'e.employee_code', 'e.full_name', 'e.email', 'e.branch')
            ->orderBy('e.full_name');

        if ($employeeId !== null) {
            $query->where('e.id', $employeeId);
        }

        return $query->get()
            ->map(static fn ($row): array => [
                'id'       => (int) $row->id,
                'code'     => (string) ($row->employee_code ?? ''),
                'name'     => (string) $row->name,
                'email'    => $row->email,
                'location' => (string) $row->location,
                'status'   => (string) $row->status,
            ])
            ->values()
            ->all();
    }

    /**
     * @return list<array{id: int, code: string, name: string, email: string|null, location: string, status: string}>
     */
    private function outsourceStaffToday(string $today): array
    {
        $eligibleIds = $this->eligibleOutsourceIds();

        if ($eligibleIds->isEmpty()) {
            return [];
        }

        $attendanceMap = DB::table('attendance_records')
            ->select(['outsource_id', 'status'])
            ->whereIn('outsource_id', $eligibleIds)
            ->whereNotNull('outsource_id')
            ->where('attendable_type', 'outsource')
            ->whereDate('attendance_date', '=', $today)
            ->get()
            ->keyBy('outsource_id');

        return DB::table('outsources as o')
            ->whereIn('o.id', $eligibleIds)
            ->select(['o.id', 'o.outsource_code', 'o.name'])
            ->orderBy('o.name')
            ->get()
            ->map(static function ($row) use ($attendanceMap): array {
                $attendance = $attendanceMap->get($row->id);
                $status     = 'Absent';

                if ($attendance) {
                    if ($attendance->status === 'incomplete') {
                        $status = 'Incomplete';
                    } elseif (in_array($attendance->status, ['present', 'late'], true)) {
                        $status = 'Present';
                    }
                }

                return [
                    'id'       => (int) $row->id,
                    'code'     => (string) ($row->outsource_code ?? ''),
                    'name'     => (string) $row->name,
                    'email'    => null,
                    'location' => 'Outsource',
                    'status'   => $status,
                ];
            })
            ->values()
            ->all();
    }

    // ──────────────────────────────────────────────────────────────────────────
    // Shared query helpers
    // ──────────────────────────────────────────────────────────────────────────

    private function eligibleEmployeesBase(string $today): \Illuminate\Database\Query\Builder
    {
        return DB::table('employees as e')
            ->leftJoin('schedule_assignments as sa', function ($join) use ($today): void {
                $join->on('sa.employee_id', '=', 'e.id')
                    ->where('sa.effective_from', '<=', $today)
                    ->where(function ($q) use ($today): void {
                        $q->whereNull('sa.effective_to')
                            ->orWhere('sa.effective_to', '>=', $today);
                    });
            })
            ->leftJoin('work_schedules as ws', function ($join): void {
                $join->on('ws.id', '=', 'sa.work_schedule_id')
                    ->where('ws.status', 'active');
            })
            ->leftJoin('attendance_records as ar', function ($join) use ($today): void {
                $join->on('ar.employee_id', '=', 'e.id')
                    ->whereDate('ar.attendance_date', '=', $today);
            })
            ->leftJoin('leave_requests as lr', function ($join) use ($today): void {
                $join->on('lr.employee_id', '=', 'e.id')
                    ->where('lr.status', '=', 'approved')
                    ->whereDate('lr.start_date', '<=', $today)
                    ->whereDate('lr.end_date', '>=', $today);
            })
            ->whereNull('e.deleted_at')
            ->where(function ($query) use ($today): void {
                $query->whereNull('e.end_date')
                    ->orWhereDate('e.end_date', '>=', $today);
            })
            ->whereNotNull('ws.id');
    }

    /**
     * @return Collection<int, int>
     */
    private function eligibleEmployeeIds(string $asOfDate, ?int $employeeId): Collection
    {
        $query = $this->eligibleEmployeesBase($asOfDate)
            ->select('e.id')
            ->groupBy('e.id');

        if ($employeeId !== null) {
            $query->where('e.id', $employeeId);
        }

        return $query->pluck('id')->map(static fn ($id) => (int) $id);
    }

    /**
     * Active outsource workers that have at least one active store assignment.
     * Only these workers are "eligible" for attendance tracking — mirrors the
     * same eligibility rule the outsource check-in flow enforces.
     *
     * @return Collection<int, int>
     */
    private function eligibleOutsourceIds(): Collection
    {
        return DB::table('outsources')
            ->join('outsource_store_assignments as osa', 'osa.outsource_id', '=', 'outsources.id')
            ->whereNull('outsources.deleted_at')
            ->where('outsources.status', 'active')
            ->where('osa.status', 'active')
            ->whereNull('osa.deleted_at')
            ->distinct()
            ->pluck('outsources.id')
            ->map(static fn ($id) => (int) $id);
    }

    /**
     * @param Collection<int, int>   $eligibleIds
     * @param Collection<string, Collection<int, object>> $attendance   grouped by date string
     * @param Collection<int, object> $leaves
     * @return list<array{date: string, present: int, absent: int, late: int, on_leave: int, rate: int}>
     */
    private function buildTrendPoints(
        CarbonImmutable $fromDate,
        CarbonImmutable $toDate,
        Collection $eligibleIds,
        Collection $attendance,
        Collection $leaves,
    ): array {
        $points = [];

        for ($date = $fromDate; $date->lessThanOrEqualTo($toDate); $date = $date->addDay()) {
            $key          = $date->toDateString();
            $dayAttendance = $attendance->get($key, collect());

            $onLeaveIds = $leaves
                ->filter(static function ($leave) use ($key): bool {
                    return $key >= CarbonImmutable::parse($leave->start_date)->toDateString()
                        && $key <= CarbonImmutable::parse($leave->end_date)->toDateString();
                })
                ->pluck('employee_id')
                ->unique()
                ->all();

            $onLeave = count($onLeaveIds);
            $present = 0;
            $late    = 0;
            $absent  = 0;
            $seen    = [];

            foreach ($dayAttendance as $row) {
                $eid = (int) $row->employee_id;
                if (isset($seen[$eid]) || in_array($eid, $onLeaveIds, true)) {
                    continue;
                }
                $seen[$eid] = true;

                if (in_array($row->status, ['present', 'incomplete'], true)) {
                    $present++;
                } elseif ($row->status === 'late') {
                    $late++;
                } elseif ($row->status === 'absent') {
                    $absent++;
                }
            }

            foreach ($eligibleIds as $eid) {
                if (!isset($seen[$eid]) && !in_array($eid, $onLeaveIds, true)) {
                    $absent++;
                }
            }

            $total = $present + $late + $absent + $onLeave;
            $rate  = $total === 0 ? 0 : (int) round((($present + $late) / $total) * 100);

            $points[] = [
                'date'     => $key,
                'present'  => $present,
                'absent'   => $absent,
                'late'     => $late,
                'on_leave' => $onLeave,
                'rate'     => $rate,
            ];
        }

        return $points;
    }

    /**
     * @return list<array{date: string, present: int, absent: int, late: int, on_leave: int, rate: int}>
     */
    private function emptyTrendRange(CarbonImmutable $fromDate, CarbonImmutable $toDate): array
    {
        $points = [];
        for ($date = $fromDate; $date->lessThanOrEqualTo($toDate); $date = $date->addDay()) {
            $points[] = [
                'date'     => $date->toDateString(),
                'present'  => 0,
                'absent'   => 0,
                'late'     => 0,
                'on_leave' => 0,
                'rate'     => 0,
            ];
        }

        return $points;
    }

    private function employeeScope(User $user): ?int
    {
        if ($user->hasRole('SUPER_ADMIN')) {
            return null;
        }

        if ($user->hasPermissionTo('employees.view')) {
            return null;
        }

        $employee = Employee::where('user_id', $user->getKey())->first()
            ?? ($user->employee()->first() ?? Employee::where('email', $user->email)->first());

        return $employee?->id;
    }
}
