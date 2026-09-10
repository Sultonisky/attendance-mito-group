<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Penalty foundation.
 *
 * - penalty_rules: configurable rule carrier (violation/frequency/threshold/
 *   points). Point calculation belongs to the future Penalty Engine.
 * - penalty_records: preserves original vs adjusted vs final points so
 *   historical records remain auditable after adjustments.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('penalty_rules', function (Blueprint $table) {
            $table->id();

            $table->string('code')->unique();
            $table->string('name');
            $table->text('description')->nullable();

            $table->decimal('points', 10, 2)->default(0);
            $table->string('frequency')->nullable();
            $table->unsignedInteger('threshold')->nullable();

            if (Schema::getConnection()->getDriverName() === 'pgsql') {
                $table->jsonb('configuration')->nullable();
            } else {
                $table->json('configuration')->nullable();
            }

            $table->string('status')->default('active');

            $table->timestamps();

            $table->index('status');
        });

        Schema::create('penalty_records', function (Blueprint $table) {
            $table->id();

            $table->foreignId('employee_id')
                ->references('id')
                ->on('employees')
                ->restrictOnDelete();
            $table->foreignId('penalty_rule_id')
                ->references('id')
                ->on('penalty_rules')
                ->restrictOnDelete();
            $table->foreignId('attendance_id')
                ->nullable()
                ->references('id')
                ->on('attendance_records')
                ->nullOnDelete();

            $table->decimal('original_points', 10, 2);
            $table->decimal('adjusted_points', 10, 2)->nullable();
            $table->decimal('final_points', 10, 2);
            $table->text('reason')->nullable();
            $table->string('status')->default('applied');
            $table->timestampTz('occurred_at');

            $table->timestamps();

            $table->index(['employee_id', 'created_at']);
            $table->index('status');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('penalty_records');
        Schema::dropIfExists('penalty_rules');
    }
};
