<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Employee work locations (city / work location + work area).
 *
 * Kept separate from `cities` / `work_locations`: those tables feed the
 * public outsource attendance dropdowns, so employee rows must not live there.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('employee_work_locations', function (Blueprint $table) {
            $table->id();

            $table->string('code')->unique();
            $table->string('name');
            $table->string('city');
            $table->string('area_type');
            $table->text('address')->nullable();
            $table->string('status')->default('active');

            $table->timestamps();
            $table->softDeletes();

            $table->index('city');
            $table->index('area_type');
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('employee_work_locations');
    }
};
