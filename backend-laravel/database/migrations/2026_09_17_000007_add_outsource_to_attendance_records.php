<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('attendance_records', function (Blueprint $table) {
            $table->dropForeign(['employee_id']);
            $table->unsignedBigInteger('employee_id')->nullable()->change();
            $table->foreign('employee_id')->references('id')->on('employees')->restrictOnDelete();

            $table->unsignedBigInteger('outsource_id')->nullable()->after('employee_id');
            $table->string('attendable_type')->default('employee')->after('outsource_id');

            $table->index(['attendable_type', 'attendance_date']);
        });

        DB::statement('ALTER TABLE attendance_records ADD CONSTRAINT chk_attendance_subject CHECK ((employee_id IS NOT NULL AND outsource_id IS NULL) OR (employee_id IS NULL AND outsource_id IS NOT NULL))');

        DB::statement('CREATE UNIQUE INDEX attendance_records_employee_date_unique ON attendance_records (employee_id, attendance_date) WHERE employee_id IS NOT NULL');
        DB::statement('CREATE UNIQUE INDEX attendance_records_outsource_date_unique ON attendance_records (outsource_id, attendance_date) WHERE outsource_id IS NOT NULL');
    }

    public function down(): void
    {
        Schema::table('attendance_records', function (Blueprint $table) {
            $table->dropForeign(['employee_id']);
            $table->unsignedBigInteger('employee_id')->nullable()->change();
            $table->foreign('employee_id')->references('id')->on('employees')->restrictOnDelete();

            $table->dropIndex(['attendable_type', 'attendance_date']);
            $table->dropColumn(['outsource_id', 'attendable_type']);
        });

        DB::statement('ALTER TABLE attendance_records DROP CONSTRAINT IF EXISTS chk_attendance_subject');
        DB::statement('DROP INDEX IF EXISTS attendance_records_employee_date_unique');
        DB::statement('DROP INDEX IF EXISTS attendance_records_outsource_date_unique');
    }
};
