<?php

namespace App\Domain\Attendance\Rules;

use App\Domain\Attendance\Exceptions\OutsideGeofenceException;
use App\Models\WorkLocation;
use App\Models\WorkLocationPin;
use Illuminate\Support\Facades\DB;

/**
 * Validates whether a point is inside a work location or pin geofence.
 *
 * Uses PostGIS when available; falls back to Haversine on SQLite.
 */
class GeofenceRule
{
    public function validate(WorkLocation $workLocation, float $latitude, float $longitude, ?float $radiusMeters = null): void
    {
        $driver = DB::connection()->getDriverName();

        if ($driver === 'pgsql' && ! empty($workLocation->location_point)) {
            $this->validatePostgisTable('work_locations', $workLocation->id, $latitude, $longitude, $radiusMeters ?? $workLocation->radius_meters);
        } else {
            $this->validateScalar(
                $workLocation->latitude,
                $workLocation->longitude,
                $latitude,
                $longitude,
                $radiusMeters ?? $workLocation->radius_meters,
                'Work location coordinates are not configured.',
            );
        }
    }

    public function validatePin(WorkLocationPin $pin, float $latitude, float $longitude, ?float $radiusMeters = null): void
    {
        $driver = DB::connection()->getDriverName();

        if ($driver === 'pgsql' && ! empty($pin->location_point)) {
            $this->validatePostgisTable('work_location_pins', $pin->id, $latitude, $longitude, $radiusMeters ?? $pin->radius_meters);
        } else {
            $this->validateScalar(
                $pin->latitude,
                $pin->longitude,
                $latitude,
                $longitude,
                $radiusMeters ?? $pin->radius_meters,
                'Pin coordinates are not configured.',
            );
        }
    }

    private function validatePostgisTable(
        string $table,
        int $id,
        float $latitude,
        float $longitude,
        ?float $radiusMeters,
    ): void {
        $radius = $radiusMeters ?? 0;

        $inside = DB::selectOne(
            "SELECT ST_DWithin(
                t.location_point::geography,
                ST_SetSRID(ST_MakePoint(?, ?), 4326)::geography,
                COALESCE(?, 0)
            ) AS inside
            FROM {$table} t
            WHERE t.id = ?",
            [$longitude, $latitude, $radius, $id]
        );

        if (empty($inside->inside)) {
            throw new OutsideGeofenceException('Employee is outside the work location geofence.');
        }
    }

    private function validateScalar(
        mixed $centerLat,
        mixed $centerLng,
        float $latitude,
        float $longitude,
        ?float $radiusMeters,
        string $missingCoordsMessage,
    ): void {
        if ($centerLat === null || $centerLng === null) {
            throw new OutsideGeofenceException($missingCoordsMessage);
        }

        $distance = $this->haversineDistance(
            $latitude,
            $longitude,
            (float) $centerLat,
            (float) $centerLng
        );

        $radius = $radiusMeters !== null ? (float) $radiusMeters : 0.0;

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
