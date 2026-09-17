<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('outsources', function (Blueprint $table) {
            $table->id();
            $table->string('outsource_code')->unique();
            $table->string('name');
            $table->string('status')->default('active');
            $table->timestamps();
            $table->softDeletes();
            $table->index('status');
            $table->index('outsource_code');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('outsources');
    }
};
