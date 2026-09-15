<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('penalty_records', function (Blueprint $table) {
            $table->string('violation_type')->nullable()->after('source')->change();
        });
    }

    public function down(): void
    {
        Schema::table('penalty_records', function (Blueprint $table) {
            $table->string('violation_type')->nullable(false)->after('source')->change();
        });
    }
};
