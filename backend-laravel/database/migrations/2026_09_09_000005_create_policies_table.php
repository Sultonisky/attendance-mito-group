<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Attendance policy foundation.
 *
 * Policies are versioned configuration carriers. The `configuration` jsonb
 * column holds future rule structure (late tolerance, geofence rules,
 * overtime rules, etc.) without embedding rules into employee records.
 * Policy evaluation belongs to the future Policy Engine.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('policies', function (Blueprint $table) {
            $table->id();

            $table->string('code')->unique();
            $table->string('name');
            $table->text('description')->nullable();

            $table->string('status')->default('draft');
            $table->date('effective_from')->nullable();
            $table->date('effective_to')->nullable();

            if (Schema::getConnection()->getDriverName() === 'pgsql') {
                $table->jsonb('configuration')->default('{}');
            } else {
                $table->json('configuration')->default('{}');
            }

            $table->timestamps();
            $table->softDeletes();

            $table->index('status');
            $table->index('effective_from');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('policies');
    }
};
