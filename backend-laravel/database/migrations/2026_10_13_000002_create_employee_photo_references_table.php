<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('employee_photo_references', function (Blueprint $table) {
            $table->id();
            $table->string('hris_employee_id')->unique();
            $table->char('hris_nik', 16)->nullable()->unique();
            $table->string('photo_path', 1024);
            $table->timestamps();
        });

        DB::table('employees')
            ->whereNotNull('photo_path')
            ->where('photo_path', '<>', '')
            ->orderBy('id')
            ->get(['employee_code', 'nik', 'photo_path', 'created_at', 'updated_at'])
            ->each(function (object $employee): void {
                DB::table('employee_photo_references')->insert([
                    'hris_employee_id' => $employee->employee_code,
                    'hris_nik' => $employee->nik,
                    'photo_path' => $employee->photo_path,
                    'created_at' => $employee->created_at ?? now(),
                    'updated_at' => $employee->updated_at ?? now(),
                ]);
            });

        Schema::table('employees', function (Blueprint $table) {
            $table->dropColumn('photo_path');
        });
    }

    public function down(): void
    {
        Schema::table('employees', function (Blueprint $table) {
            $table->string('photo_path', 1024)->nullable();
        });

        Schema::dropIfExists('employee_photo_references');
    }
};
