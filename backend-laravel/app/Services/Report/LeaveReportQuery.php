<?php

namespace App\Services\Report;

use App\Models\Employee;
use App\Models\LeaveRequest;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;

class LeaveReportQuery
{
    private const ALLOWED_SORTS = [
        'start_date' => 'leave_requests.start_date',
        'end_date' => 'leave_requests.end_date',
        'status' => 'leave_requests.status',
        'employee_name' => 'employees.full_name',
        'created_at' => 'leave_requests.created_at',
    ];

    public function paginate(User $user, array $filters): LengthAwarePaginator
    {
        $query = LeaveRequest::query()
            ->select([
                'leave_requests.id',
                'leave_requests.employee_id',
                'leave_requests.leave_type_id',
                'leave_requests.start_date',
                'leave_requests.end_date',
                'leave_requests.status',
                'leave_requests.reason',
                'leave_requests.created_at',
                'employees.full_name as employee_name',
                'leave_types.code as leave_type_code',
                'leave_types.name as leave_type_name',
            ])
            ->join('employees', 'employees.id', '=', 'leave_requests.employee_id')
            ->join('leave_types', 'leave_types.id', '=', 'leave_requests.leave_type_id')
            ->whereDate('leave_requests.start_date', '<=', $filters['to'])
            ->whereDate('leave_requests.end_date', '>=', $filters['from']);

        $employeeId = $this->employeeScope($user);
        if ($employeeId !== null) {
            $query->where('leave_requests.employee_id', $employeeId);
        }

        if ($filters['employee_id'] !== null && $employeeId === null) {
            $query->where('leave_requests.employee_id', (int) $filters['employee_id']);
        }

        $this->applySort($query, $filters['sort'] ?? 'start_date', $filters['direction'] ?? 'desc');

        return $query->paginate($filters['per_page'] ?? 25);
    }

    private function employeeScope(User $user): ?int
    {
        if ($user->hasRole('SUPER_ADMIN')) {
            return null;
        }

        if ($user->hasPermissionTo('leave.approve') || $user->hasPermissionTo('leave.reject')) {
            return null;
        }

        $employee = Employee::where('user_id', $user->getKey())->first()
            ?? ($user->employee()->first() ?? Employee::where('email', $user->email)->first());

        return $employee?->id;
    }

    private function applySort(Builder $query, string $sort, string $direction): void
    {
        $direction = strtolower($direction) === 'desc' ? 'desc' : 'asc';
        $column = self::ALLOWED_SORTS[$sort] ?? self::ALLOWED_SORTS['start_date'];

        $query->orderBy($column, $direction);
    }
}
