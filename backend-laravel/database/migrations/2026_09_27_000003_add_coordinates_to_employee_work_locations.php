<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Geofence coordinates for employee work locations (same shape as
 * work_location_pins): scalar lat/lng/radius + PostGIS geography point.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('employee_work_locations', function (Blueprint $table) {
            $table->decimal('latitude', 10, 7)->nullable()->after('address');
            $table->decimal('longitude', 10, 7)->nullable()->after('latitude');
            $table->decimal('radius_meters', 10, 2)->nullable()->after('longitude');
        });

        if (Schema::getConnection()->getDriverName() === 'pgsql') {
            Schema::table('employee_work_locations', function (Blueprint $table) {
                $table->geography('location_point', 'Point', 4326)->nullable();
                $table->spatialIndex('location_point', 'employee_work_locations_location_point_idx');
            });
        }
    }

    public function down(): void
    {
        if (Schema::getConnection()->getDriverName() === 'pgsql') {
            Schema::table('employee_work_locations', function (Blueprint $table) {
                $table->dropSpatialIndex('employee_work_locations_location_point_idx');
                $table->dropColumn('location_point');
            });
        }

        Schema::table('employee_work_locations', function (Blueprint $table) {
            $table->dropColumn(['latitude', 'longitude', 'radius_meters']);
        });
    }
};
