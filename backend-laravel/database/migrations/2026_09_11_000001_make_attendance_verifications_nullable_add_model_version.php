<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 8: Face AI / Liveness integration.
 *
 * Makes `attendance_id` nullable on `attendance_verifications` so a face
 * verification fact can be persisted independently of an attendance record
 * (e.g. during enrollment, or before a check-in record exists). Also adds an
 * explicit `model_version` column for traceability of which AI model produced
 * each verification fact.
 *
 * The `details` JSON column already exists and stores confidence / liveness /
 * processing_time_ms. Adding a dedicated `model_version` column keeps it
 * queryable without JSON parsing.
 *
 * SQLite (automated tests): table rebuild because SQLite cannot ALTER COLUMN.
 * PostgreSQL (runtime): ALTER TABLE statements.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        $driver = Schema::getConnection()->getDriverName();

        if ($driver === 'pgsql') {
            $this->upPostgres();
        } else {
            $this->upSqlite();
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        $driver = Schema::getConnection()->getDriverName();

        if ($driver === 'pgsql') {
            DB::statement('ALTER TABLE attendance_verifications DROP COLUMN IF EXISTS model_version');
            DB::statement(
                'ALTER TABLE attendance_verifications '
                .'ALTER COLUMN attendance_id SET NOT NULL'
            );
        } else {
            // SQLite: rebuild without model_version and restore NOT NULL.
            DB::statement('ALTER TABLE attendance_verifications RENAME TO attendance_verifications_old');

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
                $table->json('details')->nullable();
            });

            DB::statement('CREATE INDEX IF NOT EXISTS attendance_verifications_attendance_id_index ON attendance_verifications (attendance_id)');
            DB::statement('CREATE INDEX IF NOT EXISTS attendance_verifications_employee_id_verified_at_index ON attendance_verifications (employee_id, verified_at)');

            DB::statement(
                'INSERT INTO attendance_verifications '
                .'(id, attendance_id, attendance_session_id, employee_id, '
                .'verification_type, status, verified_at, created_at, updated_at, details) '
                .'SELECT id, attendance_id, attendance_session_id, employee_id, '
                .'verification_type, status, verified_at, created_at, updated_at, details '
                .'FROM attendance_verifications_old'
            );

            DB::statement('DROP TABLE attendance_verifications_old');
        }
    }

    /**
     * PostgreSQL: drop and recreate the FK, alter NOT NULL, add column.
     */
    protected function upPostgres(): void
    {
        // Drop existing FK so we can alter nullability.
        DB::statement(
            'ALTER TABLE attendance_verifications '
            .'DROP CONSTRAINT IF EXISTS attendance_verifications_attendance_id_foreign'
        );

        DB::statement(
            'ALTER TABLE attendance_verifications '
            .'ALTER COLUMN attendance_id DROP NOT NULL'
        );

        // Re-add the FK (cascade delete preserved from the original migration).
        DB::statement(
            'ALTER TABLE attendance_verifications '
            .'ADD CONSTRAINT attendance_verifications_attendance_id_foreign '
            .'FOREIGN KEY (attendance_id) REFERENCES attendance_records(id) ON DELETE CASCADE'
        );

        // Add explicit model_version column if it does not exist.
        if (! Schema::hasColumn('attendance_verifications', 'model_version')) {
            DB::statement('ALTER TABLE attendance_verifications ADD COLUMN model_version VARCHAR(255)');
        }

        // Index for efficient model-version lookups.
        DB::statement(
            'CREATE INDEX IF NOT EXISTS attendance_verifications_model_version_idx '
            .'ON attendance_verifications (model_version)'
        );
    }

    /**
     * SQLite: rebuild the table with the nullable attendance_id and the
     * new model_version column. SQLite cannot ALTER COLUMN so the entire
     * table must be recreated.
     */
    protected function upSqlite(): void
    {
        DB::statement('ALTER TABLE attendance_verifications RENAME TO attendance_verifications_old');

        Schema::create('attendance_verifications', function (Blueprint $table) {
            $table->id();

            // Made nullable: face verification may occur before an attendance
            // record exists (e.g. standalone verification during enrollment).
            $table->foreignId('attendance_id')
                ->nullable()
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

            // Phase 8: explicit model version for traceability.
            $table->string('model_version')->nullable();

            $table->timestamps();

            $table->json('details')->nullable();
        });

        // Indexes (IF NOT EXISTS to avoid duplicates on re-run).
        DB::statement('CREATE INDEX IF NOT EXISTS attendance_verifications_attendance_id_index ON attendance_verifications (attendance_id)');
        DB::statement('CREATE INDEX IF NOT EXISTS attendance_verifications_employee_id_verified_at_index ON attendance_verifications (employee_id, verified_at)');
        DB::statement('CREATE INDEX IF NOT EXISTS attendance_verifications_model_version_index ON attendance_verifications (model_version)');
        DB::statement('CREATE INDEX IF NOT EXISTS attendance_verifications_employee_id_verification_type_index ON attendance_verifications (employee_id, verification_type)');

        DB::statement(
            'INSERT INTO attendance_verifications '
            .'(id, attendance_id, attendance_session_id, employee_id, '
            .'verification_type, status, verified_at, created_at, updated_at, details) '
            .'SELECT id, attendance_id, attendance_session_id, employee_id, '
            .'verification_type, status, verified_at, created_at, updated_at, details '
            .'FROM attendance_verifications_old'
        );

        DB::statement('DROP TABLE attendance_verifications_old');
    }
};
