<?php

use App\Models\Outsource;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        Outsource::query()
            ->withTrashed()
            ->where(function ($query): void {
                $query->whereNull('password')->orWhere('password', '');
            })
            ->orderBy('id')
            ->each(function (Outsource $outsource): void {
                $outsource->password = Outsource::DEFAULT_LOGIN_PIN;
                $outsource->save();
            });
    }

    public function down(): void
    {
        // Intentionally empty — cannot safely reverse hashed default PINs.
    }
};
