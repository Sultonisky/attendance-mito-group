<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Enable the PostGIS extension on the application database.
 *
 * PostGIS must be installed at the PostgreSQL server level (see
 * DEVELOPMENT.md / ADR-012). This migration only enables the extension for
 * the current database; it is idempotent and fails loudly when PostGIS is
 * not available on the server.
 *
 * On SQLite (automated tests), this is a no-op since PostGIS is a
 * PostgreSQL server extension with no SQLite equivalent.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (Schema::getConnection()->getDriverName() === 'pgsql') {
            DB::statement('CREATE EXTENSION IF NOT EXISTS postgis');
        }
    }

    /**
     * Reverse the migrations.
     *
     * The extension is server-managed infrastructure shared by the
     * application and tooling (PostGIS system tables like spatial_ref_sys).
     * It is intentionally NOT dropped on rollback.
     */
    public function down(): void
    {
        // Intentionally a no-op.
    }
};
