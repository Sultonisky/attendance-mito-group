<?php

use App\Enums\MonthlyRecapStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Legacy demo seed wrote status "reviewed"; canonical value is "review".
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('monthly_recaps')
            ->where('status', 'reviewed')
            ->update(['status' => MonthlyRecapStatus::Review->value]);
    }

    public function down(): void
    {
        // Irreversible data repair — do not reintroduce the legacy alias.
    }
};
