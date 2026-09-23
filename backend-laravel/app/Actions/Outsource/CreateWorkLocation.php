<?php

namespace App\Actions\Outsource;

use App\Actions\Action;
use App\Actions\Audit\RecordAuditAction;
use App\Models\WorkLocation;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class CreateWorkLocation implements Action
{
    public function __construct(
        private readonly RecordAuditAction $audit,
    ) {}

    /**
     * @param array{name: string, city_id?: int|null, latitude?: float|null, longitude?: float|null, radius_meters?: float|null} $input
     */
    public function execute(array $input, ?User $actor, ?Request $request = null): WorkLocation
    {
        return DB::transaction(function () use ($input, $actor, $request): WorkLocation {
            $code = $this->generateCode($input['name']);

            $attributes = [
                'code'          => $code,
                'name'          => $input['name'],
                'city_id'       => $input['city_id']       ?? null,
                'latitude'      => $input['latitude']      ?? null,
                'longitude'     => $input['longitude']     ?? null,
                'radius_meters' => $input['radius_meters'] ?? null,
                'status'        => 'active',
            ];

            $location = WorkLocation::create($attributes);

            // Sync PostGIS geography column when coordinates are provided
            if (isset($input['latitude'], $input['longitude'])) {
                $this->syncLocationPoint($location, (float) $input['latitude'], (float) $input['longitude']);
                $this->createDefaultPin($location, (float) $input['latitude'], (float) $input['longitude'], $input['radius_meters'] ?? null);
            }

            $this->audit->execute(
                $actor?->getKey(),
                'work_location.created',
                $location,
                null,
                $attributes,
                $request,
            );

            return $location->fresh();
        });
    }

    private function generateCode(string $name): string
    {
        $slug = 'LOC-' . substr(md5($name . microtime()), 0, 12);
        // Ensure uniqueness
        $suffix = 0;
        $candidate = $slug;
        while (WorkLocation::where('code', $candidate)->exists()) {
            $suffix++;
            $candidate = $slug . '-' . $suffix;
        }
        return $candidate;
    }

    private function syncLocationPoint(WorkLocation $location, float $lat, float $lng): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement(
                'UPDATE work_locations SET location_point = ST_SetSRID(ST_MakePoint(?, ?), 4326) WHERE id = ?',
                [$lng, $lat, $location->id],
            );
        }
    }

    private function createDefaultPin(WorkLocation $location, float $lat, float $lng, mixed $radiusMeters): void
    {
        $pin = $location->pins()->create([
            'name' => $location->name,
            'address' => null,
            'latitude' => $lat,
            'longitude' => $lng,
            'radius_meters' => $radiusMeters,
            'status' => 'active',
        ]);

        if (DB::getDriverName() === 'pgsql') {
            DB::statement(
                'UPDATE work_location_pins SET location_point = ST_SetSRID(ST_MakePoint(?, ?), 4326) WHERE id = ?',
                [$lng, $lat, $pin->id],
            );
        }
    }
}
