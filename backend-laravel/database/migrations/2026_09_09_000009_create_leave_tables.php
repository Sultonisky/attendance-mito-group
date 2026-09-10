<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Leave foundation.
 *
 * - leave_types: annual vs special (category), with a flag controlling
 *   whether a type deducts annual leave quota.
 * - leave_requests: approval workflow history, linked to approving users.
 * - leave_balances: per employee/type/period balance with expiry.
 * - leave_transactions: append-only ledger (accrual, consumption,
 *   adjustment, expiration, reversal) preserving the reason/context for
 *   future calculations. Business time (occurred_at) is separate from
 *   insertion time (created_at).
 *
 * Accrual/eligibility/FIFO logic belongs to the future Leave Engine.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('leave_types', function (Blueprint $table) {
            $table->id();

            $table->string('code')->unique();
            $table->string('name');
            $table->string('category')->default('special');
            $table->boolean('deducts_annual_balance')->default(false);
            $table->text('description')->nullable();
            $table->string('status')->default('active');

            $table->timestamps();

            $table->index('category');
            $table->index('status');
        });

        Schema::create('leave_requests', function (Blueprint $table) {
            $table->id();

            $table->foreignId('employee_id')
                ->references('id')
                ->on('employees')
                ->restrictOnDelete();
            $table->foreignId('leave_type_id')
                ->references('id')
                ->on('leave_types')
                ->restrictOnDelete();

            $table->string('status')->default('pending');
            $table->date('start_date');
            $table->date('end_date');
            $table->text('reason')->nullable();

            $table->foreignId('approved_by')
                ->nullable()
                ->references('id')
                ->on('users')
                ->nullOnDelete();
            $table->timestampTz('approved_at')->nullable();
            $table->text('rejection_reason')->nullable();

            $table->timestamps();

            $table->index(['employee_id', 'status']);
            $table->index(['start_date', 'end_date']);
            $table->index('status');
        });

        Schema::create('leave_balances', function (Blueprint $table) {
            $table->id();

            $table->foreignId('employee_id')
                ->references('id')
                ->on('employees')
                ->restrictOnDelete();
            $table->foreignId('leave_type_id')
                ->references('id')
                ->on('leave_types')
                ->restrictOnDelete();

            $table->string('period');
            $table->decimal('balance', 10, 2)->default(0);
            $table->date('expires_at')->nullable();

            $table->timestamps();

            $table->unique(['employee_id', 'leave_type_id', 'period']);
            $table->index('expires_at');
        });

        Schema::create('leave_transactions', function (Blueprint $table) {
            $table->id();

            $table->foreignId('employee_id')
                ->references('id')
                ->on('employees')
                ->restrictOnDelete();
            $table->foreignId('leave_balance_id')
                ->references('id')
                ->on('leave_balances')
                ->restrictOnDelete();
            $table->foreignId('leave_request_id')
                ->nullable()
                ->references('id')
                ->on('leave_requests')
                ->nullOnDelete();

            $table->string('transaction_type');
            $table->decimal('amount', 10, 2);
            $table->decimal('balance_after', 10, 2)->nullable();
            $table->text('reason')->nullable();
            $table->timestampTz('occurred_at');

            if (Schema::getConnection()->getDriverName() === 'pgsql') {
                $table->jsonb('metadata')->nullable();
            } else {
                $table->json('metadata')->nullable();
            }

            $table->timestampTz('created_at')->useCurrent();

            $table->index(['employee_id', 'occurred_at']);
            $table->index('leave_balance_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('leave_transactions');
        Schema::dropIfExists('leave_balances');
        Schema::dropIfExists('leave_requests');
        Schema::dropIfExists('leave_types');
    }
};
