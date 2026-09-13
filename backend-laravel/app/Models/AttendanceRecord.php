<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'employee_id',
    'attendance_date',
    'status',
])]
class AttendanceRecord extends Model
{
    use HasFactory;

    /**
     * The employee this daily record belongs to.
     */
    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    /**
     * IN -> OUT sessions within the day.
     */
    public function sessions(): HasMany
    {
        return $this->hasMany(AttendanceSession::class);
    }

    /**
     * Raw attendance events for the day.
     *
     * The events table uses attendance_id (not attendance_record_id) as its
     * FK column, so the key must be stated explicitly.
     */
    public function events(): HasMany
    {
        return $this->hasMany(AttendanceEvent::class, 'attendance_id');
    }

    /**
     * Verification facts for the day.
     *
     * The verifications table uses attendance_id (not attendance_record_id)
     * as its FK column, so the key must be stated explicitly.
     */
    public function verifications(): HasMany
    {
        return $this->hasMany(AttendanceVerification::class, 'attendance_id');
    }

    /**
     * The attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'attendance_date' => 'date',
        ];
    }
}
