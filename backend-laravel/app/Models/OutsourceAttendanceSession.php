<?php

namespace App\Models;

use App\Enums\OutsourceAttendanceSessionStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'outsource_id',
    'work_location_id',
    'token_hash',
    'status',
    'expires_at',
    'last_used_at',
    'completed_at',
])]
class OutsourceAttendanceSession extends Model
{
    /** @use HasFactory<OutsourceAttendanceSessionFactory> */
    use HasFactory;

    protected $hidden = [
        'token_hash',
    ];

    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'last_used_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    public function outsource(): BelongsTo
    {
        return $this->belongsTo(Outsource::class);
    }

    public function workLocation(): BelongsTo
    {
        return $this->belongsTo(WorkLocation::class, 'work_location_id');
    }

    public function isActive(): bool
    {
        return $this->status === OutsourceAttendanceSessionStatus::Active->value
            && $this->expires_at->isFuture();
    }
}
