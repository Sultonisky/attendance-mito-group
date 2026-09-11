<?php

namespace Tests\Integration\PostgreSQL;

use Illuminate\Support\Facades\DB;
use Tests\TestCase;
use Tests\Traits\RefreshDatabasePostgres;

class PostGISSchemaTest extends TestCase
{
    use RefreshDatabasePostgres;

    protected function setUp(): void
    {
        parent::setUp();
        $this->refreshDatabase();
    }

    /**
     * PostGIS extension is available and enabled.
     */
    public function test_postgis_extension_is_available(): void
    {
        $version = DB::select('SELECT PostGIS_Version() AS version');

        $this->assertNotEmpty($version);
        $this->assertTrue(str_contains($version[0]->version, 'USE_GEOS'));
    }

    /**
     * PostGIS geography columns exist for spatial event/location data.
     */
    public function test_geography_columns_exist(): void
    {
        $columns = DB::select(
            "SELECT table_name, column_name FROM information_schema.columns
             WHERE table_schema = 'public' AND udt_name = 'geography'
             ORDER BY table_name"
        );

        $pairs = array_map(fn ($row) => $row->table_name.'.'.$row->column_name, $columns);

        $this->assertTrue(in_array('attendance_events.location', $pairs));
        $this->assertTrue(in_array('work_locations.location_point', $pairs));
    }

    /**
     * GIST spatial indexes exist for the geography columns.
     */
    public function test_spatial_gist_indexes_exist(): void
    {
        $indexes = DB::select(
            "SELECT indexname FROM pg_indexes
             WHERE indexdef ILIKE '%USING gist%'
             ORDER BY indexname"
        );

        $names = array_map(fn ($row) => $row->indexname, $indexes);

        $this->assertTrue(in_array('attendance_events_location_idx', $names));
        $this->assertTrue(in_array('work_locations_location_point_idx', $names));
    }
}
