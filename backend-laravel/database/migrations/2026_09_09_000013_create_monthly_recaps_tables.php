<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Monthly recap foundation.
 *
 * Monthly snapshots with the documented lifecycle
 * (DRAFT -> REVIEW -> FINALIZED -> EXPORTED). Summary data is JSONB;
 * FINALIZED recaps are business-locked by later domain engines, and the
 * per-type line items live in monthly_recap_details.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('monthly_recaps', function (Blueprint $table) {
            $table->id();

            $table->foreignId('employee_id')
                ->references('id')
                ->on('employees')
                ->restrictOnDelete();

            $table->string('period');
            $table->string('status')->default('draft');

            if (Schema::getConnection()->getDriverName() === 'pgsql') {
                $table->jsonb('summary')->nullable();
            } else {
                $table->json('summary')->nullable();
            }

            $table->timestampTz('finalized_at')->nullable();
            $table->timestampTz('exported_at')->nullable();

            $table->timestamps();

            $table->unique(['employee_id', 'period']);
            $table->index(['period', 'status']);
        });

        Schema::create('monthly_recap_details', function (Blueprint $table) {
            $table->id();

            $table->foreignId('monthly_recap_id')
                ->references('id')
                ->on('monthly_recaps')
                ->cascadeOnDelete();

            $table->string('detail_type');
            $table->string('category')->nullable();
            $table->decimal('value', 12, 2)->nullable();
            $table->unsignedInteger('quantity')->nullable();

            if (Schema::getConnection()->getDriverName() === 'pgsql') {
                $table->jsonb('metadata')->nullable();
            } else {
                $table->json('metadata')->nullable();
            }

            $table->timestamps();

            $table->index('monthly_recap_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('monthly_recap_details');
        Schema::dropIfExists('monthly_recaps');
    }
};
