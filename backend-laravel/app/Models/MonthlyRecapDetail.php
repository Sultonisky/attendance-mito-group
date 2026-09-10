<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'monthly_recap_id',
    'detail_type',
    'category',
    'value',
    'quantity',
    'metadata',
])]
class MonthlyRecapDetail extends Model
{
    /**
     * The recap this detail line belongs to.
     */
    public function monthlyRecap(): BelongsTo
    {
        return $this->belongsTo(MonthlyRecap::class);
    }

    /**
     * The attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'value' => 'float',
            'metadata' => 'array',
        ];
    }
}
