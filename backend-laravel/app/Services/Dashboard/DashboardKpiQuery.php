<?php

namespace App\Services\Dashboard;

use App\Models\Employee;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

class DashboardKpiQuery
{
    public function forToday(User $user): array
    {
        $today = CarbonImmutable::now()->toDateString();

        $sub = DB::table('employees as e')
            ->select([
                'e.id',
                DB::raw('MAX(CASE WHEN lr.id IS NOT NULL THEN 1 ELSE 0 END) as has_leave'),
                DB::raw("MAX(CASE WHEN ar.status IN ('present', 'incomplete') THEN 1 ELSE 0 END) as is_present"),
                DB::raw("MAX(CASE WHEN ar.status = 'late' THEN 1 ELSE 0 END) as is_late"),
                DB::raw("MAX(CASE WHEN ar.status = 'absent' OR ar.id IS NULL THEN 1 ELSE 0 END) as is_absent"),
            ])
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
            ->whereNotNull('ws.id')
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

        $employeeId = $this->employeeScope($user);
        if ($employeeId !== null) {
            $query->where('classified.id', $employeeId);
        }

        $result = $query->first();

        return [
            'date' => $today,
            'present' => (int) ($result->present ?? 0),
            'absent' => (int) ($result->absent ?? 0),
            'late' => (int) ($result->late ?? 0),
            'on_leave' => (int) ($result->on_leave ?? 0),
        ];
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
