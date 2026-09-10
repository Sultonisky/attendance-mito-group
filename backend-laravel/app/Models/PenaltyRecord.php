<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'employee_id',
    'penalty_rule_id',
    'attendance_id',
    'original_points',
    'adjusted_points',
    'final_points',
    'reason',
    'status',
    'occurred_at',
])]
class PenaltyRecord extends Model
{
    /**
     * The penalized employee.
     */
    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    /**
     * The rule applied.
     */
    public function penaltyRule(): BelongsTo
    {
        return $this->belongsTo(PenaltyRule::class);
    }

    /**
     * The attendance record that produced the violation.
     */
    public function attendanceRecord(): BelongsTo
    {
        return $this->belongsTo(AttendanceRecord::class, 'attendance_id');
    }

    /**
     * The attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'original_points' => 'float',
            'adjusted_points' => 'float',
            'final_points' => 'float',
            'occurred_at' => 'datetime',
        ];
    }
}
