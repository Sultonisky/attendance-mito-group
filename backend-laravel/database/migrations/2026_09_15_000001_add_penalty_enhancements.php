<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('penalty_records', function (Blueprint $table) {
            $table->string('source')->default('system')->after('status');
            $table->string('violation_type')->after('source');
            $table->text('violation_custom')->nullable()->after('violation_type');
            $table->string('idempotency_key')->nullable()->unique()->after('violation_custom');

            $table->index(['employee_id', 'source', 'status']);
        });
    }

    public function down(): void
    {
        Schema::table('penalty_records', function (Blueprint $table) {
            $table->dropUnique(['idempotency_key']);
            $table->dropIndex(['employee_id', 'source', 'status']);
            $table->dropColumn(['source', 'violation_type', 'violation_custom', 'idempotency_key']);
        });
    }
};
