<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('employee_face_embeddings', function (Blueprint $table) {
            //
        });

        if (Schema::getConnection()->getDriverName() === 'pgsql') {
            DB::statement(
                'CREATE UNIQUE INDEX IF NOT EXISTS employee_face_embeddings_active_unique '
                .'ON employee_face_embeddings (employee_face_profile_id) '
                .'WHERE status = \'active\''
            );
        }
    }

    public function down(): void
    {
        if (Schema::getConnection()->getDriverName() === 'pgsql') {
            DB::statement('DROP INDEX IF EXISTS employee_face_embeddings_active_unique');
        }
    }
};
