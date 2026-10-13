<?php

namespace App\Models;

use Database\Factories\EmployeeWorkLocationFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\DB;

#[Fillable([
    'code',
    'name',
    'city',
    'area_type',
    'address',
    'latitude',
    'longitude',
    'radius_meters',
    'status',
])]
class EmployeeWorkLocation extends Model
{
    /** @use HasFactory<EmployeeWorkLocationFactory> */
    use HasFactory, SoftDeletes;

    public const DEFAULT_RADIUS_METERS = 150;

    public function employees(): BelongsToMany
    {
        return $this->belongsToMany(
            Employee::class,
            'employee_work_location_assignments',
            'employee_work_location_id',
            'employee_nik',
            'id',
            'nik',
        )->withPivot('status')->withTimestamps();
    }

    public function activeEmployees(): BelongsToMany
    {
        return $this->employees()->wherePivot('status', 'active');
    }

    /**
     * Keep the PostGIS geography column in sync with scalar lat/lng.
     */
    public function syncLocationPoint(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        if ($this->latitude === null || $this->longitude === null) {
            DB::statement('UPDATE employee_work_locations SET location_point = NULL WHERE id = ?', [$this->id]);

            return;
        }

        DB::statement(
            'UPDATE employee_work_locations SET location_point = ST_SetSRID(ST_MakePoint(?, ?), 4326) WHERE id = ?',
            [(float) $this->longitude, (float) $this->latitude, $this->id],
        );
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
