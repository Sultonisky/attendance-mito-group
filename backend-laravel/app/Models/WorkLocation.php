<?php

namespace App\Models;

use App\Models\Outsource;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable([
    'city_id',
    'code',
    'name',
    'latitude',
    'longitude',
    'radius_meters',
    'location_point',
    'status',
])]
class WorkLocation extends Model
{
    /** @use HasFactory<WorkLocationFactory> */
    use HasFactory, SoftDeletes;

    /**
     * @return BelongsTo<City, $this>
     */
    public function city(): BelongsTo
    {
        return $this->belongsTo(City::class);
    }

    /**
     * @return BelongsToMany<Outsource>
     */
    public function outsources(): BelongsToMany
    {
        return $this->belongsToMany(Outsource::class, 'outsource_store_assignments', 'store_id', 'outsource_id')
            ->withPivot('status')
            ->withTimestamps();
    }

    /**
     * @return HasMany<WorkLocationPin, $this>
     */
    public function pins(): HasMany
    {
        return $this->hasMany(WorkLocationPin::class);
    }

    /**
     * @return HasMany<WorkLocationPin, $this>
     */
    public function activePins(): HasMany
    {
        return $this->pins()->where('status', 'active');
    }

    /**
     * The attributes that should be cast.
     *
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
