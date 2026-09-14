<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'attendance_id',
    'attendance_session_id',
    'employee_id',
    'verification_type',
    'status',
    'details',
    'model_version',
    'verified_at',
])]
class AttendanceVerification extends Model
{
    /** @use HasFactory<AttendanceVerificationFactory> */
    use HasFactory;

    /**
     * The daily record the verification applies to.
     */
    public function attendanceRecord(): BelongsTo
    {
        return $this->belongsTo(AttendanceRecord::class, 'attendance_id');
    }

    /**
     * The session the verification applies to (may be null).
     */
    public function attendanceSession(): BelongsTo
    {
        return $this->belongsTo(AttendanceSession::class);
    }

    /**
     * The employee being verified.
     */
    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    /**
     * The attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'details' => 'array',
            'model_version' => 'string',
            'verified_at' => 'datetime',
        ];
    }
}
