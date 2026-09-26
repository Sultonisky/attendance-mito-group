<?php

namespace App\Models;

use Database\Factories\AttendanceCorrectionRequestFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'employee_id',
    'attendance_record_id',
    'attendance_session_id',
    'request_type',
    'attendance_date',
    'requested_check_in_at',
    'requested_check_out_at',
    'reason',
    'status',
    'reviewed_by',
    'reviewed_at',
    'rejection_reason',
])]
class AttendanceCorrectionRequest extends Model
{
    /** @use HasFactory<AttendanceCorrectionRequestFactory> */
    use HasFactory;

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function attendanceRecord(): BelongsTo
    {
        return $this->belongsTo(AttendanceRecord::class);
    }

    public function attendanceSession(): BelongsTo
    {
        return $this->belongsTo(AttendanceSession::class);
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'attendance_date' => 'date',
            'requested_check_in_at' => 'datetime',
            'requested_check_out_at' => 'datetime',
            'reviewed_at' => 'datetime',
        ];
    }
}
