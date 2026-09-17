<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('employee_face_embeddings', function (Blueprint $table) {
            $table->string('idempotency_key')->nullable()->after('provider');
            $table->unique('idempotency_key');
        });
    }

    public function down(): void
    {
        Schema::table('employee_face_embeddings', function (Blueprint $table) {
            $table->dropUnique('employee_face_embeddings_idempotency_key_unique');
            $table->dropColumn('idempotency_key');
        });
    }
};
