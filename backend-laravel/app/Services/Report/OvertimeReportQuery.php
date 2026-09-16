<?php

namespace App\Services\Report;

use App\Models\Employee;
use App\Models\OvertimeRecord;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;

class OvertimeReportQuery
{
    private const ALLOWED_SORTS = [
        'date' => 'overtime_records.date',
        'status' => 'overtime_records.status',
        'employee_name' => 'employees.full_name',
        'potential_minutes' => 'overtime_records.potential_minutes',
        'approved_minutes' => 'overtime_records.approved_minutes',
        'created_at' => 'overtime_records.created_at',
    ];

    public function paginate(User $user, array $filters): LengthAwarePaginator
    {
        $query = OvertimeRecord::query()
            ->select([
                'overtime_records.id',
                'overtime_records.employee_id',
                'overtime_records.attendance_id',
                'overtime_records.overtime_request_id',
                'overtime_records.date',
                'overtime_records.potential_minutes',
                'overtime_records.requested_minutes',
                'overtime_records.approved_minutes',
                'overtime_records.actual_minutes',
                'overtime_records.status',
                'overtime_records.created_at',
                'employees.full_name as employee_name',
            ])
            ->join('employees', 'employees.id', '=', 'overtime_records.employee_id')
            ->whereDate('overtime_records.date', '>=', $filters['from'])
            ->whereDate('overtime_records.date', '<=', $filters['to']);

        $employeeId = $this->employeeScope($user);
        if ($employeeId !== null) {
            $query->where('overtime_records.employee_id', $employeeId);
        }

        if ($filters['employee_id'] !== null && $employeeId === null) {
            $query->where('overtime_records.employee_id', (int) $filters['employee_id']);
        }

        $this->applySort($query, $filters['sort'] ?? 'date', $filters['direction'] ?? 'desc');

        return $query->paginate($filters['per_page'] ?? 25);
    }

    private function employeeScope(User $user): ?int
    {
        if ($user->hasRole('SUPER_ADMIN')) {
            return null;
        }

        if ($user->hasPermissionTo('overtime.approve') || $user->hasPermissionTo('overtime.reject')) {
            return null;
        }

        $employee = Employee::where('user_id', $user->getKey())->first()
            ?? ($user->employee()->first() ?? Employee::where('email', $user->email)->first());

        return $employee?->id;
    }

    private function applySort(Builder $query, string $sort, string $direction): void
    {
        $direction = strtolower($direction) === 'desc' ? 'desc' : 'asc';
        $column = self::ALLOWED_SORTS[$sort] ?? self::ALLOWED_SORTS['date'];

        $query->orderBy($column, $direction);
    }
}
