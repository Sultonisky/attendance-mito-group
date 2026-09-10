<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Work locations / geofence foundation.
 *
 * - latitude/longitude: scalar fallback coordinates (numeric).
 * - location_point: PostGIS geography(Point, 4326) for real spatial queries
 *   (point-in-polygon, distance). A GIST spatial index is created for it.
 *
 * On SQLite (automated tests), geography columns and spatial indexes are
 * skipped because they have no SQLite equivalent. The scalar latitude/
 * longitude columns remain available for basic coordinate storage.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('work_locations', function (Blueprint $table) {
            $table->id();

            $table->string('code')->unique();
            $table->string('name');

            $table->decimal('latitude', 10, 7)->nullable();
            $table->decimal('longitude', 10, 7)->nullable();
            $table->decimal('radius_meters', 10, 2)->nullable();

            $table->string('status')->default('active');

            $table->timestamps();
            $table->softDeletes();

            $table->index('status');

            if (Schema::getConnection()->getDriverName() === 'pgsql') {
                $table->geography('location_point', 'Point', 4326)->nullable();
                $table->spatialIndex('location_point', 'work_locations_location_point_idx');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('work_locations');
    }
};
