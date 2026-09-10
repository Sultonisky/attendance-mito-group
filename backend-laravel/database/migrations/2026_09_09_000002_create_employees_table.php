<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Core employee table.
 *
 * - employee_code: stable business identifier (unique), not the primary key.
 * - direct_superior_id / indirect_superior_id: self-referencing hierarchy.
 * - Soft deletes: historical HR/attendance records must never be erased by
 *   deleting an employee; dependent business records (attendance, leave,
 *   overtime, penalty, recaps) use RESTRICT and therefore hard-deleting an
 *   employee with history is blocked.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('employees', function (Blueprint $table) {
            $table->id();

            $table->string('employee_code')->unique();
            $table->string('full_name');
            $table->string('email')->nullable()->unique();
            $table->string('phone', 32)->nullable();

            $table->string('employment_status')->default('permanent');
            $table->date('join_date')->nullable();
            $table->date('end_date')->nullable();

            $table->string('job_position')->nullable();
            $table->string('department')->nullable();
            $table->string('division')->nullable();
            $table->string('branch')->nullable();
            $table->string('job_level')->nullable();
            $table->string('grade')->nullable();

            $table->foreignId('direct_superior_id')
                ->nullable()
                ->references('id')
                ->on('employees')
                ->restrictOnDelete();
            $table->foreignId('indirect_superior_id')
                ->nullable()
                ->references('id')
                ->on('employees')
                ->restrictOnDelete();

            $table->timestamps();
            $table->softDeletes();

            $table->index('employment_status');
            $table->index('join_date');
            $table->index('department');
            $table->index('division');
            $table->index('branch');
            $table->index('direct_superior_id');
            $table->index('indirect_superior_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('employees');
    }
};
