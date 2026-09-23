<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Outsource public login credentials (kode + password).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('outsources', function (Blueprint $table) {
            $table->string('password')->nullable()->after('name');
        });
    }

    public function down(): void
    {
        Schema::table('outsources', function (Blueprint $table) {
            $table->dropColumn('password');
        });
    }
};
