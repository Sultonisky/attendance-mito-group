<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Permission (izin) request foundation.
 *
 * Distinct from leave: permission covers short-term session-level requests
 * such as late arrival, early checkout, personal, or business-related
 * permission. Approval history references users; employee references are
 * RESTRICT so history is preserved.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('permission_requests', function (Blueprint $table) {
            $table->id();

            $table->foreignId('employee_id')
                ->references('id')
                ->on('employees')
                ->restrictOnDelete();

            $table->string('permission_type');
            $table->string('status')->default('pending');
            $table->timestampTz('start_at');
            $table->timestampTz('end_at');
            $table->text('reason')->nullable();

            $table->foreignId('approved_by')
                ->nullable()
                ->references('id')
                ->on('users')
                ->nullOnDelete();
            $table->timestampTz('approved_at')->nullable();

            $table->timestamps();

            $table->index(['employee_id', 'status']);
            $table->index('status');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('permission_requests');
    }
};
