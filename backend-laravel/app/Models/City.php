<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable([
    'name',
    'code',
    'status',
])]
class City extends Model
{
    /** @use HasFactory<CityFactory> */
    use HasFactory, SoftDeletes;

    /**
     * @return HasMany<WorkLocation>
     */
    public function workLocations(): HasMany
    {
        return $this->hasMany(WorkLocation::class);
    }
}
