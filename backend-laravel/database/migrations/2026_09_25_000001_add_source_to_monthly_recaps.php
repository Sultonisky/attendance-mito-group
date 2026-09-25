<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Monthly recap subjects: employee XOR outsource (attendance-only).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('monthly_recaps', function (Blueprint $table) {
            $table->dropUnique(['employee_id', 'period']);
        });

        Schema::table('monthly_recaps', function (Blueprint $table) {
            $table->dropForeign(['employee_id']);
        });

        Schema::table('monthly_recaps', function (Blueprint $table) {
            $table->unsignedBigInteger('employee_id')->nullable()->change();
            $table->foreign('employee_id')->references('id')->on('employees')->restrictOnDelete();

            $table->unsignedBigInteger('outsource_id')->nullable()->after('employee_id');
            $table->foreign('outsource_id')->references('id')->on('outsources')->restrictOnDelete();

            $table->string('source')->default('employee')->after('outsource_id');
            $table->index(['source', 'period', 'status']);
        });

        DB::table('monthly_recaps')->whereNull('source')->update(['source' => 'employee']);

        if (Schema::getConnection()->getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE monthly_recaps ADD CONSTRAINT chk_monthly_recap_subject CHECK (
                (source = \'employee\' AND employee_id IS NOT NULL AND outsource_id IS NULL)
                OR (source = \'outsource\' AND outsource_id IS NOT NULL AND employee_id IS NULL)
            )');
        }

        DB::statement('CREATE UNIQUE INDEX IF NOT EXISTS monthly_recaps_employee_period_unique ON monthly_recaps (employee_id, period) WHERE employee_id IS NOT NULL');
        DB::statement('CREATE UNIQUE INDEX IF NOT EXISTS monthly_recaps_outsource_period_unique ON monthly_recaps (outsource_id, period) WHERE outsource_id IS NOT NULL');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS monthly_recaps_employee_period_unique');
        DB::statement('DROP INDEX IF EXISTS monthly_recaps_outsource_period_unique');

        if (Schema::getConnection()->getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE monthly_recaps DROP CONSTRAINT IF EXISTS chk_monthly_recap_subject');
        }

        // Keep only employee rows before restoring NOT NULL employee_id.
        DB::table('monthly_recaps')->whereNotNull('outsource_id')->delete();

        Schema::table('monthly_recaps', function (Blueprint $table) {
            $table->dropForeign(['outsource_id']);
            $table->dropIndex(['source', 'period', 'status']);
            $table->dropColumn(['outsource_id', 'source']);
        });

        Schema::table('monthly_recaps', function (Blueprint $table) {
            $table->dropForeign(['employee_id']);
        });

        Schema::table('monthly_recaps', function (Blueprint $table) {
            $table->unsignedBigInteger('employee_id')->nullable(false)->change();
            $table->foreign('employee_id')->references('id')->on('employees')->restrictOnDelete();
            $table->unique(['employee_id', 'period']);
        });
    }
};
