<?php

namespace Tests\Integration\PostgreSQL;

use App\Models\WorkLocation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class GeofenceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->refreshDatabase();
    }

    public function test_postgis_point_inside_geofence_is_detected(): void
    {
        $location = WorkLocation::factory()->create([
            'latitude' => -6.2,
            'longitude' => 106.8,
            'radius_meters' => 1000,
            'location_point' => \DB::raw('ST_SetSRID(ST_MakePoint(106.8, -6.2), 4326)'),
        ]);

        $inside = \DB::selectOne(
            'SELECT ST_DWithin(
                wl.location_point::geography,
                ST_SetSRID(ST_MakePoint(?, ?), 4326)::geography,
                COALESCE(wl.radius_meters, 0)
            ) AS inside
            FROM work_locations wl
            WHERE wl.id = ?',
            [106.8001, -6.2001, $location->id]
        );

        $this->assertTrue((bool) $inside->inside);
    }

    public function test_postgis_point_outside_geofence_is_detected(): void
    {
        $location = WorkLocation::factory()->create([
            'latitude' => -6.2,
            'longitude' => 106.8,
            'radius_meters' => 100,
            'location_point' => \DB::raw('ST_SetSRID(ST_MakePoint(106.8, -6.2), 4326)'),
        ]);

        $inside = \DB::selectOne(
            'SELECT ST_DWithin(
                wl.location_point::geography,
                ST_SetSRID(ST_MakePoint(?, ?), 4326)::geography,
                COALESCE(wl.radius_meters, 0)
            ) AS inside
            FROM work_locations wl
            WHERE wl.id = ?',
            [106.9, -6.3, $location->id]
        );

        $this->assertFalse((bool) $inside->inside);
    }
}
