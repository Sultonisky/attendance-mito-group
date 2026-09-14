<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable([
    'code',
    'name',
    'description',
    'status',
    'effective_from',
    'effective_to',
    'configuration',
])]
class Policy extends Model
{
    /** @use HasFactory<PolicyFactory> */
    use HasFactory, SoftDeletes;

    /**
     * Employee policy assignments.
     */
    public function policyAssignments(): HasMany
    {
        return $this->hasMany(PolicyAssignment::class);
    }

    /**
     * The attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'effective_from' => 'date',
            'effective_to' => 'date',
            'deleted_at' => 'datetime',
            'configuration' => 'array',
        ];
    }
}
