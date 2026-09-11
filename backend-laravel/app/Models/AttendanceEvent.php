<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'employee_id',
    'attendance_id',
    'attendance_session_id',
    'event_type',
    'occurred_at',
    'latitude',
    'longitude',
    'location',
    'accuracy_meters',
    'source',
    'device_metadata',
])]
class AttendanceEvent extends Model
{
    /** @use HasFactory<AttendanceEventFactory> */
    use HasFactory;

    /**
     * The employee who generated the event.
     */
    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    /**
     * The daily record the event is attached to (may be null after cleanup).
     */
    public function attendanceRecord(): BelongsTo
    {
        return $this->belongsTo(AttendanceRecord::class, 'attendance_id');
    }

    /**
     * The session the event is attached to (may be null after cleanup).
     */
    public function attendanceSession(): BelongsTo
    {
        return $this->belongsTo(AttendanceSession::class);
    }

    /**
     * The attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'device_metadata' => 'array',
            'occurred_at' => 'datetime',
            'latitude' => 'float',
            'longitude' => 'float',
            'accuracy_meters' => 'float',
        ];
    }
}
