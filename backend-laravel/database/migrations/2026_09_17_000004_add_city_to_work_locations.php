<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('work_locations', function (Blueprint $table) {
            $table->foreignId('city_id')->nullable()->after('code')->constrained()->nullOnDelete();
            $table->index('city_id');
        });
    }

    public function down(): void
    {
        Schema::table('work_locations', function (Blueprint $table) {
            $table->dropForeign(['city_id']);
            $table->dropColumn('city_id');
        });
    }
};
