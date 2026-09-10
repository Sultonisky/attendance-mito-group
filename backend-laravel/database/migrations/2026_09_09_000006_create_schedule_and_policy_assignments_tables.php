<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Employee schedule/policy assignment foundation.
 *
 * Historical, normalized assignments (effective_from/effective_to) allow an
 * employee's schedule/policy to change over time without rewriting history.
 * Active-assignment resolution belongs to the future Schedule/Policy Engines.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('schedule_assignments', function (Blueprint $table) {
            $table->id();

            $table->foreignId('employee_id')
                ->references('id')
                ->on('employees')
                ->cascadeOnDelete();
            $table->foreignId('work_schedule_id')
                ->references('id')
                ->on('work_schedules')
                ->restrictOnDelete();

            $table->date('effective_from');
            $table->date('effective_to')->nullable();

            $table->timestamps();

            $table->unique(['employee_id', 'effective_from']);
            $table->index('work_schedule_id');
        });

        Schema::create('policy_assignments', function (Blueprint $table) {
            $table->id();

            $table->foreignId('employee_id')
                ->references('id')
                ->on('employees')
                ->cascadeOnDelete();
            $table->foreignId('policy_id')
                ->references('id')
                ->on('policies')
                ->restrictOnDelete();

            $table->date('effective_from');
            $table->date('effective_to')->nullable();

            $table->timestamps();

            $table->unique(['employee_id', 'policy_id', 'effective_from']);
            $table->index('policy_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('policy_assignments');
        Schema::dropIfExists('schedule_assignments');
    }
};
