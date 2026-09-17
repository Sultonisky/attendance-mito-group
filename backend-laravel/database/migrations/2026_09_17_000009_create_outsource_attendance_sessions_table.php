<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('outsource_attendance_sessions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('outsource_id')->constrained()->cascadeOnDelete();
            $table->foreignId('work_location_id')->constrained('work_locations')->cascadeOnDelete();
            $table->string('token_hash', 64)->unique();
            $table->string('status')->default('active');
            $table->timestamp('expires_at');
            $table->timestamp('last_used_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->index('status');
            $table->index('expires_at');
            $table->index(['outsource_id', 'work_location_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('outsource_attendance_sessions');
    }
};
