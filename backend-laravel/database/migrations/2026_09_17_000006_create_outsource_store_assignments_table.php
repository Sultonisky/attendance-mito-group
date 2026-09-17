<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('outsource_store_assignments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('outsource_id')->constrained()->cascadeOnDelete();
            $table->foreignId('store_id')->constrained('work_locations')->cascadeOnDelete();
            $table->string('status')->default('active');
            $table->softDeletes();
            $table->timestamps();

            $table->unique(['outsource_id', 'store_id']);
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('outsource_store_assignments');
    }
};
