<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Work schedule foundation.
 *
 * A work schedule groups one or more shifts. A shift defines a single
 * IN -> OUT time interval (including breaks and cross-midnight support).
 * Schedule calculation logic belongs to the future Schedule Engine.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('work_schedules', function (Blueprint $table) {
            $table->id();

            $table->string('code')->nullable()->unique();
            $table->string('name');
            $table->text('description')->nullable();

            $table->string('status')->default('active');
            $table->date('effective_from')->nullable();
            $table->date('effective_to')->nullable();

            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('shifts', function (Blueprint $table) {
            $table->id();

            $table->foreignId('work_schedule_id')
                ->references('id')
                ->on('work_schedules')
                ->cascadeOnDelete();

            $table->string('name');
            $table->time('start_time');
            $table->time('end_time');
            $table->time('break_start')->nullable();
            $table->time('break_end')->nullable();
            $table->boolean('cross_midnight')->default(false);

            $table->timestamps();

            $table->index('work_schedule_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('shifts');
        Schema::dropIfExists('work_schedules');
    }
};
