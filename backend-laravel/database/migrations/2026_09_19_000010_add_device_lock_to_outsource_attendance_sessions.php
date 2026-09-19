<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('outsource_attendance_sessions', function (Blueprint $table) {
            $table->string('device_fingerprint', 128)->nullable()->after('token_hash');
            $table->string('ip_address', 45)->nullable()->after('device_fingerprint');
            $table->text('user_agent')->nullable()->after('ip_address');

            $table->index('device_fingerprint');
            $table->index(['device_fingerprint', 'status']);
        });
    }

    public function down(): void
    {
        Schema::table('outsource_attendance_sessions', function (Blueprint $table) {
            $table->dropIndex(['device_fingerprint']);
            $table->dropIndex(['device_fingerprint', 'status']);
            $table->dropColumn(['device_fingerprint', 'ip_address', 'user_agent']);
        });
    }
};
