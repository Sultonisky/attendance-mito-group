<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('attendance_events', function (Blueprint $table) {
            $table->foreignId('employee_work_location_id')
                ->nullable()
                ->after('work_location_pin_id')
                ->constrained('employee_work_locations')
                ->nullOnDelete();
            $table->index('employee_work_location_id');
        });
    }

    public function down(): void
    {
        Schema::table('attendance_events', function (Blueprint $table) {
            $table->dropForeign(['employee_work_location_id']);
            $table->dropIndex(['employee_work_location_id']);
            $table->dropColumn('employee_work_location_id');
        });
    }
};
