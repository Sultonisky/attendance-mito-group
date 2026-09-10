<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Audit log foundation.
 *
 * - actor_id references users (SET NULL if the user is removed so the log
 *   survives); actor may be null for system/guest actions.
 * - auditable_type/auditable_id: polymorphic entity reference.
 * - old_values/new_values: JSONB snapshots.
 *
 * SECURITY: sensitive values (passwords, password hashes, session tokens,
 * Sanctum tokens, raw biometric embeddings, authentication secrets) must
 * never be written into audit logs. Callers of the future audit system are
 * responsible for scrubbing payloads before persisting.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('audit_logs', function (Blueprint $table) {
            $table->id();

            $table->foreignId('actor_id')
                ->nullable()
                ->references('id')
                ->on('users')
                ->nullOnDelete();

            $table->string('action');
            $table->string('auditable_type')->nullable();
            $table->unsignedBigInteger('auditable_id')->nullable();

            if (Schema::getConnection()->getDriverName() === 'pgsql') {
                $table->jsonb('old_values')->nullable();
                $table->jsonb('new_values')->nullable();
                $table->jsonb('metadata')->nullable();
            } else {
                $table->json('old_values')->nullable();
                $table->json('new_values')->nullable();
                $table->json('metadata')->nullable();
            }

            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();

            $table->timestampTz('created_at')->useCurrent();

            $table->index(['auditable_type', 'auditable_id']);
            $table->index('actor_id');
            $table->index(['action', 'created_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('audit_logs');
    }
};
