<?php

namespace App\Actions\Outsource;

use App\Actions\Action;
use App\Actions\Audit\RecordAuditAction;
use App\Models\WorkLocation;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class UpdateWorkLocation implements Action
{
    public function __construct(
        private readonly RecordAuditAction $audit,
    ) {}

    /**
     * @param array{name?: string, city_id?: int|null, latitude?: float|null, longitude?: float|null, radius_meters?: float|null, status?: string} $input
     */
    public function execute(WorkLocation $location, array $input, ?User $actor, ?Request $request = null): WorkLocation
    {
        return DB::transaction(function () use ($location, $input, $actor, $request): WorkLocation {
            $old = [
                'name'          => $location->name,
                'city_id'       => $location->city_id,
                'latitude'      => $location->latitude,
                'longitude'     => $location->longitude,
                'radius_meters' => $location->radius_meters,
                'status'        => $location->status,
            ];

            $fillable = array_filter([
                'name'          => $input['name']          ?? null,
                'city_id'       => $input['city_id']       ?? null,
                'latitude'      => $input['latitude']      ?? null,
                'longitude'     => $input['longitude']     ?? null,
                'radius_meters' => $input['radius_meters'] ?? null,
                'status'        => $input['status']        ?? null,
            ], fn ($v) => $v !== null);

            if (!empty($fillable)) {
                $location->update($fillable);
            }

            // Re-sync PostGIS point if coordinates changed
            $lat = $input['latitude']  ?? $location->latitude;
            $lng = $input['longitude'] ?? $location->longitude;
            if ($lat !== null && $lng !== null && DB::getDriverName() === 'pgsql') {
                DB::statement(
                    'UPDATE work_locations SET location_point = ST_SetSRID(ST_MakePoint(?, ?), 4326) WHERE id = ?',
                    [(float) $lng, (float) $lat, $location->id],
                );
            }

            $location->refresh();

            $this->audit->execute(
                $actor?->getKey(),
                'work_location.updated',
                $location,
                $old,
                $fillable,
                $request,
            );

            return $location;
        });
    }
}
