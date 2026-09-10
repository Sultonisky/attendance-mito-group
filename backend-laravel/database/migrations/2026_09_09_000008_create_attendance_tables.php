<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Attendance foundation.
 *
 * Layered model:
 *   attendance_records      -> employee/day-level context
 *   attendance_sessions     -> IN/OUT intervals within a day
 *   attendance_events       -> raw event history (audit + recalculation)
 *   attendance_verifications-> AI/geofence/GPS verification facts
 *
 * - attendance_records: UNIQUE(employee_id, attendance_date) prevents
 *   accidental duplicate daily records.
 * - attendance_events: fk to record/session are SET NULL on delete so raw
 *   event history survives record cleanup; the employee FK is RESTRICT so
 *   event history cannot be erased by deleting an employee.
 * - Business timestamps use timestamp with time zone; occurred_at (business
 *   time) is distinct from created_at (insertion time).
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
        Schema::create('attendance_records', function (Blueprint $table) {
            $table->id();

            $table->foreignId('employee_id')
                ->references('id')
                ->on('employees')
                ->restrictOnDelete();

            $table->date('attendance_date');
            $table->string('status')->default('incomplete');

            $table->timestamps();

            $table->unique(['employee_id', 'attendance_date']);
            $table->index(['employee_id', 'created_at']);
            $table->index('status');
        });

        Schema::create('attendance_sessions', function (Blueprint $table) {
            $table->id();

            $table->foreignId('attendance_record_id')
                ->references('id')
                ->on('attendance_records')
                ->cascadeOnDelete();

            $table->timestampTz('check_in_at');
            $table->timestampTz('check_out_at')->nullable();
            $table->unsignedInteger('duration_minutes')->nullable();
            $table->string('status')->default('open');

            $table->timestamps();

            $table->index(['attendance_record_id', 'status']);
        });

        Schema::create('attendance_events', function (Blueprint $table) {
            $table->id();

            $table->foreignId('employee_id')
                ->references('id')
                ->on('employees')
                ->restrictOnDelete();
            $table->foreignId('attendance_id')
                ->nullable()
                ->references('id')
                ->on('attendance_records')
                ->nullOnDelete();
            $table->foreignId('attendance_session_id')
                ->nullable()
                ->references('id')
                ->on('attendance_sessions')
                ->nullOnDelete();

            $table->string('event_type');
            $table->timestampTz('occurred_at');

            $table->decimal('latitude', 10, 7)->nullable();
            $table->decimal('longitude', 10, 7)->nullable();
            $table->decimal('accuracy_meters', 10, 2)->nullable();

            $table->string('source')->nullable();

            $table->timestamps();

            $table->index(['employee_id', 'occurred_at']);
            $table->index(['attendance_id', 'event_type']);
            $table->index('attendance_session_id');

            if (Schema::getConnection()->getDriverName() === 'pgsql') {
                $table->geography('location', 'Point', 4326)->nullable();
                $table->jsonb('device_metadata')->nullable();
                $table->spatialIndex('location', 'attendance_events_location_idx');
            } else {
                // SQLite-compatible JSON column for device metadata.
                $table->json('device_metadata')->nullable();
            }
        });

        Schema::create('attendance_verifications', function (Blueprint $table) {
            $table->id();

            $table->foreignId('attendance_id')
                ->references('id')
                ->on('attendance_records')
                ->cascadeOnDelete();
            $table->foreignId('attendance_session_id')
                ->nullable()
                ->references('id')
                ->on('attendance_sessions')
                ->nullOnDelete();
            $table->foreignId('employee_id')
                ->references('id')
                ->on('employees')
                ->restrictOnDelete();

            $table->string('verification_type');
            $table->string('status');
            $table->timestampTz('verified_at')->nullable();

            $table->timestamps();

            $table->index('attendance_id');
            $table->index(['employee_id', 'verified_at']);

            if (Schema::getConnection()->getDriverName() === 'pgsql') {
                $table->jsonb('details')->nullable();
            } else {
                $table->json('details')->nullable();
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('attendance_verifications');
        Schema::dropIfExists('attendance_events');
        Schema::dropIfExists('attendance_sessions');
        Schema::dropIfExists('attendance_records');
    }
};
