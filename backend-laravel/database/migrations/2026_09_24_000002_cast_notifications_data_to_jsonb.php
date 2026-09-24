<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * PostgreSQL stores Laravel notification payloads as text by default.
     * Cast to jsonb so JSON operators / casts work reliably.
     */
    public function up(): void
    {
        if (Schema::getConnection()->getDriverName() !== 'pgsql') {
            return;
        }

        if (! Schema::hasTable('notifications')) {
            return;
        }

        DB::statement('ALTER TABLE notifications ALTER COLUMN data TYPE jsonb USING data::jsonb');
    }

    public function down(): void
    {
        if (Schema::getConnection()->getDriverName() !== 'pgsql') {
            return;
        }

        if (! Schema::hasTable('notifications')) {
            return;
        }

        DB::statement('ALTER TABLE notifications ALTER COLUMN data TYPE text USING data::text');
    }
};
