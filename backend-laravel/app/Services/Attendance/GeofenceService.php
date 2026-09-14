<?php

namespace App\Services\Attendance;

use App\Models\WorkLocation;
use Illuminate\Support\Facades\DB;

/**
 * Geofence evaluation for attendance check-in/out.
 *
 * On PostgreSQL/PostGIS this uses spatial functions. On SQLite (automated
 * tests) it falls back to a scalar Haversine distance check against the
 * stored radius_meters.
 */
class GeofenceService
{
    /**
     * Determine whether a coordinate is inside the given work location.
     *
     * @return array{passed: bool, distance_meters: float|null, method: string}
     */
    public function evaluate(WorkLocation $location, float $latitude, float $longitude): array
    {
        $driver = DB::connection()->getDriverName();

        if ($driver === 'pgsql' && $location->location_point !== null) {
            return $this->evaluatePostGis($location, $latitude, $longitude);
        }

        return $this->evaluateScalar($location, $latitude, $longitude);
    }

    /**
     * PostGIS-backed evaluation using ST_DWithin.
     *
     * @return array{passed: bool, distance_meters: float|null, method: string}
     */
    private function evaluatePostGis(WorkLocation $location, float $latitude, float $longitude): array
    {
        $distance = DB::selectOne(
            'SELECT ST_DDistance(
                location_point,
                ST_SetSRID(ST_MakePoint(?, ?), 4326)::geography
             ) AS distance_meters
             FROM work_locations
             WHERE id = ?',
            [$longitude, $latitude, $location->id]
        );

        $distanceMeters = isset($distance->distance_meters) ? (float) $distance->distance_meters : null;

        return [
            'passed' => $distanceMeters !== null && $distanceMeters <= (float) $location->radius_meters,
            'distance_meters' => $distanceMeters,
            'method' => 'postgis',
        ];
    }

    /**
     * Scalar fallback using Haversine distance.
     *
     * @return array{passed: bool, distance_meters: float|null, method: string}
     */
    private function evaluateScalar(WorkLocation $location, float $latitude, float $longitude): array
    {
        if ($location->latitude === null || $location->longitude === null || $location->radius_meters === null) {
            return [
                'passed' => true,
                'distance_meters' => null,
                'method' => 'scalar_unverified',
            ];
        }

        $distanceMeters = $this->haversineDistance(
            $latitude,
            $longitude,
            (float) $location->latitude,
            (float) $location->longitude
        );

        return [
            'passed' => $distanceMeters <= (float) $location->radius_meters,
            'distance_meters' => $distanceMeters,
            'method' => 'scalar',
        ];
    }

    /**
     * Haversine distance in meters between two WGS84 points.
     */
    private function haversineDistance(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $earthRadius = 6371000;

        $dLat = deg2rad($lat2 - $lat1);
        $dLng = deg2rad($lng2 - $lng1);

        $a = sin($dLat / 2) ** 2
            + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng / 2) ** 2;

        $c = 2 * atan2(sqrt($a), sqrt(max(0.0, 1 - $a)));

        return (float) ($earthRadius * $c);
    }
}
