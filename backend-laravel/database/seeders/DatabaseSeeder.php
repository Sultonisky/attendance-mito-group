<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    private const BREAK_GLASS_EMAIL = 'superadmin@mito.co.id';

    /**
     * Seed the application's database.
     *
     * Always seeded (idempotent, every environment):
     *   1. RolesAndPermissionsSeeder — RBAC baseline.
     *   2. Initial dashboard login accounts (seedDashboardUsers) — bootstrap
     *      only, while the users table is empty — so a fresh deployment has
     *      working dashboard credentials, including hisar.hesti@mito.co.id
     *      (ADMIN) and reginald.hirawan@mito.co.id (SUPER_ADMIN). Override the
     *      default password via SEED_USER_PASSWORD.
     *   3. Break-glass SUPER_ADMIN (ensureSuperAdminAccess) — every run, even
     *      when step 2 fails, but only acts when no active SUPER_ADMIN exists.
     *
     * Local-only (dummy/demo data):
     *   DevelopmentDataSeeder — factory-driven cities, stores, schedules,
     *   policies, employees, outsource attendance demo (pins / overnight).
     */
    public function run(): void
    {
        $this->call(RolesAndPermissionsSeeder::class);

        try {
            $this->seedDashboardUsers();
        } finally {
            // A failed bootstrap still rethrows (deploy fails loudly), but the
            // dashboard must never be left without a SUPER_ADMIN login.
            $this->ensureSuperAdminAccess();
        }

        if (! app()->environment('local')) {
            return;
        }

        // Factory-driven demo data (cities, stores, schedules, policies,
        // employees, outsource workers, attendance history). Dev only.
        $this->call(DevelopmentDataSeeder::class);
    }

    /**
     * Initial dashboard login accounts — active in every environment.
     *
     * These are auth/RBAC users only. They must NOT create or link
     * `employees` rows (USER stays a plain user; ADMIN / SUPER_ADMIN are
     * operators). Real employee↔user links belong to HR employee management
     * or DevelopmentDataSeeder (local demo only).
     *
     * Named operator accounts (hisar / reginald) are the production-facing
     * logins. Demo accounts (superadmin@ / admin@ / user@) remain for local
     * and QA convenience and are still safe to seed in production.
     *
     * Bootstrap only: db:seed runs on every deploy, and PostgreSQL (managed
     * via the Users page) is the source of truth once any account exists.
     * Re-seeding would reset roles/permissions edited by SUPER_ADMIN and
     * resurrect deleted accounts with the default password.
     */
    protected function seedDashboardUsers(): void
    {
        if (User::query()->exists()) {
            return;
        }

        $dashboardUsers = [
            // Production-facing operators
            ['name' => 'Hisar Hesti', 'email' => 'hisar.hesti@mito.co.id', 'role' => 'ADMIN'],
            ['name' => 'Reginald Hirawan', 'email' => 'reginald.hirawan@mito.co.id', 'role' => 'SUPER_ADMIN'],
            // Demo / bootstrap accounts
            ['name' => 'Super Admin', 'email' => 'superadmin@mito.co.id', 'role' => 'SUPER_ADMIN'],
            ['name' => 'Admin', 'email' => 'admin@mito.co.id', 'role' => 'ADMIN'],
            ['name' => 'User', 'email' => 'user@mito.co.id', 'role' => 'USER'],
        ];

        $password = $this->seedPassword();

        // All-or-nothing: a partial bootstrap would leave the users table
        // non-empty, and the exists() gate would never seed the rest.
        DB::transaction(function () use ($dashboardUsers, $password): void {
            foreach ($dashboardUsers as $dashboardUser) {
                $user = User::create([
                    'email' => $dashboardUser['email'],
                    'name' => $dashboardUser['name'],
                    'password' => $password,
                ]);

                // Copies the role template onto the user (SUPER_ADMIN: all
                // permissions) — see User::assignRole.
                $user->syncRoles([$dashboardUser['role']]);
            }
        });
    }

    /**
     * Lockout guard: if no active SUPER_ADMIN exists (bootstrap failed, or
     * every SUPER_ADMIN was demoted/deactivated), create or restore
     * superadmin@mito.co.id as an active SUPER_ADMIN.
     *
     * Does nothing while any active SUPER_ADMIN exists, so Users page edits
     * stay authoritative. An existing account keeps its current password —
     * resetting it to the known default would be a backdoor.
     */
    protected function ensureSuperAdminAccess(): void
    {
        if (User::role('SUPER_ADMIN')->where('status', 'active')->exists()) {
            return;
        }

        DB::transaction(function (): void {
            $user = User::firstOrCreate(
                ['email' => self::BREAK_GLASS_EMAIL],
                ['name' => 'Super Admin', 'password' => $this->seedPassword()],
            );

            if ($user->status !== 'active') {
                $user->update(['status' => 'active']);
            }

            $user->syncRoles(['SUPER_ADMIN']);
            $user->syncPermissions(Permission::all());
        });

        $this->command?->warn('No active SUPER_ADMIN found — restored '.self::BREAK_GLASS_EMAIL.'.');
    }

    protected function seedPassword(): string
    {
        return (string) env('SEED_USER_PASSWORD', 'Mahakarya2026');
    }
}
