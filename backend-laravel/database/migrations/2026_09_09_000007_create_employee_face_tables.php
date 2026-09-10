<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Face verification data foundation.
 *
 * Only the storage foundation for future AI/CV integration is created here.
 *
 * - employee_face_profiles: one profile per employee, tracks the model
 *   version used to enroll the profile.
 * - employee_face_embeddings: NEVER stores raw embeddings. `embedding_reference`
 *   is a reference to a secure external store; the FastAPI service owns any
 *   actual vector material and returns facts only.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('employee_face_profiles', function (Blueprint $table) {
            $table->id();

            $table->foreignId('employee_id')
                ->references('id')
                ->on('employees')
                ->cascadeOnDelete();

            $table->string('model_version');
            $table->string('status')->default('active');
            $table->json('metadata')->nullable();

            $table->timestamps();

            $table->unique('employee_id');
            $table->index('status');
        });

        Schema::create('employee_face_embeddings', function (Blueprint $table) {
            $table->id();

            $table->foreignId('employee_face_profile_id')
                ->references('id')
                ->on('employee_face_profiles')
                ->cascadeOnDelete();

            $table->string('model_version');
            $table->string('embedding_reference')->nullable();
            $table->string('provider')->nullable();
            $table->string('status')->default('active');

            $table->timestamps();

            $table->index('employee_face_profile_id');
            $table->index('status');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('employee_face_embeddings');
        Schema::dropIfExists('employee_face_profiles');
    }
};
