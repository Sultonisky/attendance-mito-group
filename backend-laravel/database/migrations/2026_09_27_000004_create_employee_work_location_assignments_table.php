<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('employee_work_location_assignments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->foreignId('employee_work_location_id')->constrained()->cascadeOnDelete();
            $table->string('status')->default('active');
            $table->timestamps();

            $table->unique(['employee_id', 'employee_work_location_id'], 'ewl_assignments_employee_location_unique');
            $table->index(['employee_work_location_id', 'status'], 'ewl_assignments_location_status_idx');
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('employee_work_location_assignments');
    }
};
