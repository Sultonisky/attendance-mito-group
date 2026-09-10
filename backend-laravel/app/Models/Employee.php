<?php

namespace App\Models;

use Database\Factories\EmployeeFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable([
    'employee_code',
    'full_name',
    'email',
    'phone',
    'employment_status',
    'join_date',
    'end_date',
    'job_position',
    'department',
    'division',
    'branch',
    'job_level',
    'grade',
    'direct_superior_id',
    'indirect_superior_id',
])]
class Employee extends Model
{
    /** @use HasFactory<EmployeeFactory> */
    use HasFactory, SoftDeletes;

    /**
     * Direct superior in the reporting hierarchy.
     */
    public function directSuperior(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'direct_superior_id');
    }

    /**
     * Indirect senior leader in the reporting hierarchy.
     */
    public function indirectSuperior(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'indirect_superior_id');
    }

    /**
     * Employees who report directly to this employee.
     */
    public function subordinates(): HasMany
    {
        return $this->hasMany(Employee::class, 'direct_superior_id');
    }

    /**
     * Employees who report indirectly to this employee.
     */
    public function indirectSubordinates(): HasMany
    {
        return $this->hasMany(Employee::class, 'indirect_superior_id');
    }

    /**
     * Schedule assignments (historical).
     */
    public function scheduleAssignments(): HasMany
    {
        return $this->hasMany(ScheduleAssignment::class);
    }

    /**
     * Policy assignments (historical).
     */
    public function policyAssignments(): HasMany
    {
        return $this->hasMany(PolicyAssignment::class);
    }

    /**
     * Daily attendance records.
     */
    public function attendanceRecords(): HasMany
    {
        return $this->hasMany(AttendanceRecord::class);
    }

    /**
     * Leave requests.
     */
    public function leaveRequests(): HasMany
    {
        return $this->hasMany(LeaveRequest::class);
    }

    /**
     * Leave balances.
     */
    public function leaveBalances(): HasMany
    {
        return $this->hasMany(LeaveBalance::class);
    }

    /**
     * Permission (izin) requests.
     */
    public function permissionRequests(): HasMany
    {
        return $this->hasMany(PermissionRequest::class);
    }

    /**
     * Overtime requests.
     */
    public function overtimeRequests(): HasMany
    {
        return $this->hasMany(OvertimeRequest::class);
    }

    /**
     * Overtime records.
     */
    public function overtimeRecords(): HasMany
    {
        return $this->hasMany(OvertimeRecord::class);
    }

    /**
     * Penalty records.
     */
    public function penaltyRecords(): HasMany
    {
        return $this->hasMany(PenaltyRecord::class);
    }

    /**
     * Monthly recaps.
     */
    public function monthlyRecaps(): HasMany
    {
        return $this->hasMany(MonthlyRecap::class);
    }

    /**
     * Face verification profiles.
     */
    public function faceProfiles(): HasMany
    {
        return $this->hasMany(EmployeeFaceProfile::class);
    }

    /**
     * The attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'join_date' => 'date',
            'end_date' => 'date',
            'deleted_at' => 'datetime',
        ];
    }
}
