<?php

namespace App\Models;

use App\Models\Outsource;
use App\Models\OutsourceStoreAssignment;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable([
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
     * @return BelongsToMany<Outsource>
     */
    public function outsources(): BelongsToMany
    {
        return $this->belongsToMany(Outsource::class, 'outsource_store_assignments', 'store_id', 'outsource_id')
            ->withPivot('status')
            ->withTimestamps();
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
