<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Overtime foundation.
 *
 * Two-table design keeps the four overtime concepts distinct:
 *   potential minutes (detected)      -> overtime_records.potential_minutes
 *   requested minutes                 -> overtime_requests.requested_minutes
 *   approved minutes                  -> overtime_requests.approved_minutes
 *   actual/recorded minutes           -> overtime_records.actual_minutes
 *
 * Detection/qualification/rounding/limits belong to the future Overtime
 * Engine. Employee references are RESTRICT to preserve history.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('overtime_requests', function (Blueprint $table) {
            $table->id();

            $table->foreignId('employee_id')
                ->references('id')
                ->on('employees')
                ->restrictOnDelete();
            $table->foreignId('attendance_id')
                ->nullable()
                ->references('id')
                ->on('attendance_records')
                ->restrictOnDelete();

            $table->date('date');
            $table->unsignedInteger('requested_minutes')->default(0);
            $table->unsignedInteger('approved_minutes')->nullable();
            $table->string('status')->default('pending');
            $table->text('reason')->nullable();

            $table->foreignId('approved_by')
                ->nullable()
                ->references('id')
                ->on('users')
                ->nullOnDelete();
            $table->timestampTz('approved_at')->nullable();

            $table->timestamps();

            $table->index(['employee_id', 'status']);
            $table->index('date');
        });

        Schema::create('overtime_records', function (Blueprint $table) {
            $table->id();

            $table->foreignId('employee_id')
                ->references('id')
                ->on('employees')
                ->restrictOnDelete();
            $table->foreignId('attendance_id')
                ->nullable()
                ->references('id')
                ->on('attendance_records')
                ->restrictOnDelete();
            $table->foreignId('overtime_request_id')
                ->nullable()
                ->references('id')
                ->on('overtime_requests')
                ->nullOnDelete();

            $table->date('date');
            $table->unsignedInteger('potential_minutes')->default(0);
            $table->unsignedInteger('requested_minutes')->default(0);
            $table->unsignedInteger('approved_minutes')->nullable();
            $table->unsignedInteger('actual_minutes')->nullable();
            $table->string('status')->default('potential');

            $table->timestamps();

            $table->index(['employee_id', 'date']);
            $table->index('status');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('overtime_records');
        Schema::dropIfExists('overtime_requests');
    }
};
