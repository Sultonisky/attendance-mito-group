<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Multi-pin geofence under each work location (cabang).
 *
 * - work_location_pins: address/lat-lng pins belonging to a cabang
 * - outsource_assignment_pins: optional allowlist per assignment
 *   (empty subset = all active pins of the assigned cabang)
 * - attendance_events.work_location_pin_id: which pin was used for IN/OUT
 *
 * Existing work_locations lat/lng are backfilled as one default pin each.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('work_location_pins', function (Blueprint $table) {
            $table->id();
            $table->foreignId('work_location_id')->constrained('work_locations')->cascadeOnDelete();
            $table->string('name');
            $table->string('address')->nullable();
            $table->decimal('latitude', 10, 7)->nullable();
            $table->decimal('longitude', 10, 7)->nullable();
            $table->decimal('radius_meters', 10, 2)->nullable();
            $table->string('status')->default('active');
            $table->timestamps();
            $table->softDeletes();

            $table->index(['work_location_id', 'status']);
            $table->index('status');
        });

        if (Schema::getConnection()->getDriverName() === 'pgsql') {
            Schema::table('work_location_pins', function (Blueprint $table) {
                $table->geography('location_point', 'Point', 4326)->nullable();
                $table->spatialIndex('location_point', 'work_location_pins_location_point_idx');
            });
        }

        Schema::create('outsource_assignment_pins', function (Blueprint $table) {
            $table->id();
            $table->foreignId('assignment_id')
                ->constrained('outsource_store_assignments')
                ->cascadeOnDelete();
            $table->foreignId('pin_id')
                ->constrained('work_location_pins')
                ->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['assignment_id', 'pin_id']);
            $table->index('pin_id');
        });

        Schema::table('attendance_events', function (Blueprint $table) {
            $table->foreignId('work_location_pin_id')
                ->nullable()
                ->after('outsource_id')
                ->constrained('work_location_pins')
                ->nullOnDelete();
        });

        $this->backfillPinsFromWorkLocations();
    }

    public function down(): void
    {
        Schema::table('attendance_events', function (Blueprint $table) {
            $table->dropConstrainedForeignId('work_location_pin_id');
        });

        Schema::dropIfExists('outsource_assignment_pins');
        Schema::dropIfExists('work_location_pins');
    }

    private function backfillPinsFromWorkLocations(): void
    {
        $locations = DB::table('work_locations')
            ->whereNull('deleted_at')
            ->whereNotNull('latitude')
            ->whereNotNull('longitude')
            ->get(['id', 'name', 'latitude', 'longitude', 'radius_meters', 'status']);

        $now = now();
        $isPgsql = Schema::getConnection()->getDriverName() === 'pgsql';

        foreach ($locations as $location) {
            $pinId = DB::table('work_location_pins')->insertGetId([
                'work_location_id' => $location->id,
                'name' => $location->name,
                'address' => null,
                'latitude' => $location->latitude,
                'longitude' => $location->longitude,
                'radius_meters' => $location->radius_meters,
                'status' => $location->status === 'active' ? 'active' : 'inactive',
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            if ($isPgsql) {
                DB::statement(
                    'UPDATE work_location_pins SET location_point = ST_SetSRID(ST_MakePoint(?, ?), 4326) WHERE id = ?',
                    [(float) $location->longitude, (float) $location->latitude, $pinId]
                );
            }
        }
    }
};
