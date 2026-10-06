<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('employee_work_location_options', function (Blueprint $table) {
            $table->id();
            $table->string('type', 32);
            $table->string('name');
            $table->string('normalized_name');
            $table->timestamps();
            $table->unique(['type', 'normalized_name']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('employee_work_location_options');
    }
};
