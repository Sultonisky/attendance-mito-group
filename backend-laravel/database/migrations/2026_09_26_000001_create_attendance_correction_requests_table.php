<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Employee attendance correction requests (forgotten clock in/out).
 *
 * Requests stay pending until an admin approves/rejects them. Approval
 * applies session corrections; the employee cannot mutate attendance directly.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('attendance_correction_requests', function (Blueprint $table) {
            $table->id();

            $table->foreignId('employee_id')
                ->references('id')
                ->on('employees')
                ->restrictOnDelete();

            $table->foreignId('attendance_record_id')
                ->nullable()
                ->references('id')
                ->on('attendance_records')
                ->nullOnDelete();

            $table->foreignId('attendance_session_id')
                ->nullable()
                ->references('id')
                ->on('attendance_sessions')
                ->nullOnDelete();

            $table->string('request_type');
            $table->date('attendance_date');
            $table->timestampTz('requested_check_in_at')->nullable();
            $table->timestampTz('requested_check_out_at')->nullable();
            $table->text('reason');
            $table->string('status')->default('pending');

            $table->foreignId('reviewed_by')
                ->nullable()
                ->references('id')
                ->on('users')
                ->nullOnDelete();
            $table->timestampTz('reviewed_at')->nullable();
            $table->text('rejection_reason')->nullable();

            $table->timestamps();

            $table->index(['employee_id', 'status']);
            $table->index('attendance_date');
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('attendance_correction_requests');
    }
};
