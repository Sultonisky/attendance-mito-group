<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable([
    'work_location_id',
    'name',
    'address',
    'latitude',
    'longitude',
    'radius_meters',
    'location_point',
    'status',
])]
class WorkLocationPin extends Model
{
    /** @use HasFactory<\Database\Factories\WorkLocationPinFactory> */
    use HasFactory, SoftDeletes;

    /**
     * @return BelongsTo<WorkLocation, $this>
     */
    public function workLocation(): BelongsTo
    {
        return $this->belongsTo(WorkLocation::class);
    }

    /**
     * @return BelongsToMany<OutsourceStoreAssignment, $this>
     */
    public function assignments(): BelongsToMany
    {
        return $this->belongsToMany(
            OutsourceStoreAssignment::class,
            'outsource_assignment_pins',
            'pin_id',
            'assignment_id'
        )->withTimestamps();
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'latitude' => 'float',
            'longitude' => 'float',
            'radius_meters' => 'float',
            'deleted_at' => 'datetime',
        ];
    }
}
