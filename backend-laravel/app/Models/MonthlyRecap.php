<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'employee_id',
    'outsource_id',
    'source',
    'period',
    'status',
    'summary',
    'finalized_at',
    'exported_at',
])]
class MonthlyRecap extends Model
{
    /** @use HasFactory<\Database\Factories\MonthlyRecapFactory> */
    use HasFactory;

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function outsource(): BelongsTo
    {
        return $this->belongsTo(Outsource::class);
    }

    public function details(): HasMany
    {
        return $this->hasMany(MonthlyRecapDetail::class);
    }

    /**
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
