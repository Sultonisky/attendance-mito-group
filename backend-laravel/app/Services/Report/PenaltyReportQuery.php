<?php

namespace App\Services\Report;

use App\Models\Employee;
use App\Models\PenaltyRecord;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;

class PenaltyReportQuery
{
    private const ALLOWED_SORTS = [
        'occurred_at' => 'penalty_records.occurred_at',
        'status' => 'penalty_records.status',
        'employee_name' => 'employees.full_name',
        'final_points' => 'penalty_records.final_points',
        'created_at' => 'penalty_records.created_at',
    ];

    public function paginate(User $user, array $filters): LengthAwarePaginator
    {
        $query = PenaltyRecord::query()
            ->select([
                'penalty_records.id',
                'penalty_records.employee_id',
                'penalty_records.penalty_rule_id',
                'penalty_records.attendance_id',
                'penalty_records.source',
                'penalty_records.violation_type',
                'penalty_records.violation_custom',
                'penalty_records.original_points',
                'penalty_records.adjusted_points',
                'penalty_records.final_points',
                'penalty_records.reason',
                'penalty_records.status',
                'penalty_records.occurred_at',
                'penalty_records.created_at',
                'employees.full_name as employee_name',
                'penalty_rules.name as penalty_rule_name',
            ])
            ->join('employees', 'employees.id', '=', 'penalty_records.employee_id')
            ->leftJoin('penalty_rules', 'penalty_rules.id', '=', 'penalty_records.penalty_rule_id')
            ->whereDate('penalty_records.occurred_at', '>=', $filters['from'])
            ->whereDate('penalty_records.occurred_at', '<=', $filters['to']);

        $employeeId = $this->employeeScope($user);
        if ($employeeId !== null) {
            $query->where('penalty_records.employee_id', $employeeId);
        }

        if ($filters['employee_id'] !== null && $employeeId === null) {
            $query->where('penalty_records.employee_id', (int) $filters['employee_id']);
        }

        $this->applySort($query, $filters['sort'] ?? 'occurred_at', $filters['direction'] ?? 'desc');

        return $query->paginate($filters['per_page'] ?? 25);
    }

    private function employeeScope(User $user): ?int
    {
        if ($user->hasRole('SUPER_ADMIN')) {
            return null;
        }

        if ($user->hasPermissionTo('penalty.adjust') || $user->hasPermissionTo('penalty.void')) {
            return null;
        }

        $employee = Employee::where('user_id', $user->getKey())->first()
            ?? ($user->employee()->first() ?? Employee::where('email', $user->email)->first());

        return $employee?->id;
    }

    private function applySort(Builder $query, string $sort, string $direction): void
    {
        $direction = strtolower($direction) === 'desc' ? 'desc' : 'asc';
        $column = self::ALLOWED_SORTS[$sort] ?? self::ALLOWED_SORTS['occurred_at'];

        $query->orderBy($column, $direction);
    }
}
