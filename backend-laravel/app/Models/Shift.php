<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'work_schedule_id',
    'name',
    'start_time',
    'end_time',
    'break_start',
    'break_end',
    'cross_midnight',
])]
class Shift extends Model
{
    use HasFactory;

    /**
     * The schedule this shift belongs to.
     */
    public function workSchedule(): BelongsTo
    {
        return $this->belongsTo(WorkSchedule::class);
    }

    /**
     * The attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'start_time' => 'datetime:H:i:s',
            'end_time' => 'datetime:H:i:s',
            'break_start' => 'datetime:H:i:s',
            'break_end' => 'datetime:H:i:s',
            'cross_midnight' => 'boolean',
        ];
    }
}
