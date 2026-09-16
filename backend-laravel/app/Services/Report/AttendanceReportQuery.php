<?php

namespace App\Services\Report;

use App\Models\AttendanceRecord;
use App\Models\Employee;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;

class AttendanceReportQuery
{
    private const ALLOWED_SORTS = [
        'attendance_date' => 'attendance_records.attendance_date',
        'employee_name' => 'employees.full_name',
        'status' => 'attendance_records.status',
        'created_at' => 'attendance_records.created_at',
    ];

    public function paginate(User $user, array $filters): LengthAwarePaginator
    {
        $query = AttendanceRecord::query()
            ->select([
                'attendance_records.id',
                'attendance_records.employee_id',
                'attendance_records.attendance_date',
                'attendance_records.status',
                'attendance_records.created_at',
                'employees.full_name as employee_name',
            ])
            ->join('employees', 'employees.id', '=', 'attendance_records.employee_id')
            ->whereDate('attendance_records.attendance_date', '>=', $filters['from'])
            ->whereDate('attendance_records.attendance_date', '<=', $filters['to']);

        $employeeId = $this->employeeScope($user);
        if ($employeeId !== null) {
            $query->where('attendance_records.employee_id', $employeeId);
        }

        if ($filters['employee_id'] !== null && $employeeId === null) {
            $query->where('attendance_records.employee_id', (int) $filters['employee_id']);
        }

        $this->applySort($query, $filters['sort'] ?? 'attendance_date', $filters['direction'] ?? 'asc');

        return $query->paginate($filters['per_page'] ?? 25);
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

    private function applySort(Builder $query, string $sort, string $direction): void
    {
        $direction = strtolower($direction) === 'desc' ? 'desc' : 'asc';
        $column = self::ALLOWED_SORTS[$sort] ?? self::ALLOWED_SORTS['attendance_date'];

        $query->orderBy($column, $direction);
    }
}
