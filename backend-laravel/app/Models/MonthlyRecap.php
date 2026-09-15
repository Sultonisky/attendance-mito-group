<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'employee_id',
    'period',
    'status',
    'summary',
    'finalized_at',
    'exported_at',
])]
class MonthlyRecap extends Model
{
    /** @use HasFactory<MonthlyRecapFactory> */
    use HasFactory;

    /**
     * The employee this recap belongs to.
     */
    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    /**
     * Per-type snapshot line items.
     */
    public function details(): HasMany
    {
        return $this->hasMany(MonthlyRecapDetail::class);
    }

    /**
     * The attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'finalized_at' => 'datetime',
            'exported_at' => 'datetime',
            'summary' => 'array',
        ];
    }
}
