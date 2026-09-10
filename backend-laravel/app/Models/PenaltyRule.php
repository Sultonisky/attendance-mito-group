<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'code',
    'name',
    'description',
    'points',
    'frequency',
    'threshold',
    'configuration',
    'status',
])]
class PenaltyRule extends Model
{
    /**
     * Penalty records created from this rule.
     */
    public function penaltyRecords(): HasMany
    {
        return $this->hasMany(PenaltyRecord::class);
    }

    /**
     * The attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'points' => 'float',
            'configuration' => 'array',
        ];
    }
}
