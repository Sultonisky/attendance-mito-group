<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('attendance_events', function (Blueprint $table) {
            $table->dropForeign(['employee_id']);
            $table->unsignedBigInteger('employee_id')->nullable()->change();
            $table->foreign('employee_id')->references('id')->on('employees')->restrictOnDelete();

            $table->unsignedBigInteger('outsource_id')->nullable()->after('employee_id');
        });

        if (Schema::getConnection()->getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE attendance_events ADD CONSTRAINT chk_event_subject CHECK ((employee_id IS NOT NULL AND outsource_id IS NULL) OR (employee_id IS NULL AND outsource_id IS NOT NULL))');
        }
    }

    public function down(): void
    {
        Schema::table('attendance_events', function (Blueprint $table) {
            $table->dropForeign(['employee_id']);
            $table->unsignedBigInteger('employee_id')->nullable()->change();
            $table->foreign('employee_id')->references('id')->on('employees')->restrictOnDelete();

            $table->dropColumn('outsource_id');
        });

        if (Schema::getConnection()->getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE attendance_events DROP CONSTRAINT IF EXISTS chk_event_subject');
        }
    }
};
