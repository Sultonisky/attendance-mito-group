<?php

namespace App\Domain\Attendance\Rules;

use App\Domain\Attendance\Exceptions\OutsideGeofenceException;
use App\Models\WorkLocation;
use Illuminate\Support\Facades\DB;

/**
 * Validates whether a point is inside a work location's geofence.
 *
 * Uses PostGIS when available; falls back to a simple scalar check on SQLite.
 */
class GeofenceRule
{
    public function validate(WorkLocation $workLocation, float $latitude, float $longitude): void
    {
        $driver = DB::connection()->getDriverName();

        if ($driver === 'pgsql' && ! empty($workLocation->location_point)) {
            $this->validatePostgis($workLocation, $latitude, $longitude);
        } else {
            $this->validateScalar($workLocation, $latitude, $longitude);
        }
    }

    private function validatePostgis(WorkLocation $workLocation, float $latitude, float $longitude): void
    {
        $inside = DB::selectOne(
            'SELECT ST_DWithin(
                ?::geography,
                ST_SetSRID(ST_MakePoint(?, ?), 4326)::geography,
                COALESCE(?, 0)
            ) AS inside',
            [
                $workLocation->location_point,
                $longitude,
                $latitude,
                $workLocation->radius_meters ?? 0,
            ]
        );

        if (empty($inside->inside)) {
            throw new OutsideGeofenceException('Employee is outside the work location geofence.');
        }
    }

    private function validateScalar(WorkLocation $workLocation, float $latitude, float $longitude): void
    {
        if ($workLocation->latitude === null || $workLocation->longitude === null) {
            return;
        }

        $distance = $this->haversineDistance(
            $latitude,
            $longitude,
            (float) $workLocation->latitude,
            (float) $workLocation->longitude
        );

        $radius = (float) ($workLocation->radius_meters ?? 0);

        if ($distance > $radius) {
            throw new OutsideGeofenceException('Employee is outside the work location geofence.');
        }
    }

    private function haversineDistance(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $earthRadius = 6371000;

        $dLat = deg2rad($lat2 - $lat1);
        $dLng = deg2rad($lng2 - $lng1);

        $a = sin($dLat / 2) * sin($dLat / 2)
            + cos(deg2rad($lat1)) * cos(deg2rad($lat2))
            * sin($dLng / 2) * sin($dLng / 2);

        $c = 2 * atan2(sqrt($a), sqrt(1 - $a));

        return $earthRadius * $c;
    }
}
