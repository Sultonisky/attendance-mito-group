<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable([
    'outsource_id',
    'store_id',
    'status',
])]
class OutsourceStoreAssignment extends Model
{
    /** @use HasFactory<OutsourceStoreAssignmentFactory> */
    use HasFactory, SoftDeletes;

    /**
     * @return BelongsTo<Outsource>
     */
    public function outsource(): BelongsTo
    {
        return $this->belongsTo(Outsource::class);
    }

    /**
     * @return BelongsTo<WorkLocation>
     */
    public function store(): BelongsTo
    {
        return $this->belongsTo(WorkLocation::class, 'store_id');
    }

    /**
     * @return HasMany<OutsourceAssignmentPin, $this>
     */
    public function assignmentPins(): HasMany
    {
        return $this->hasMany(OutsourceAssignmentPin::class, 'assignment_id');
    }

    /**
     * Explicit pin allowlist for this assignment (empty = all active cabang pins).
     *
     * @return BelongsToMany<WorkLocationPin, $this>
     */
    public function pins(): BelongsToMany
    {
        return $this->belongsToMany(
            WorkLocationPin::class,
            'outsource_assignment_pins',
            'assignment_id',
            'pin_id'
        )->withTimestamps();
    }
}
