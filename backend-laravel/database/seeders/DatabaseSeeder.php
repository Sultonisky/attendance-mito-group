<?php

namespace Database\Seeders;

use App\Models\Employee;
use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     *
     * Always seeded (idempotent, every environment):
     *   1. RolesAndPermissionsSeeder — RBAC baseline.
     *   2. Initial dashboard login accounts (seedDashboardUsers) so a fresh
     *      deployment always has working dashboard credentials. Override the
     *      default password in production via SEED_USER_PASSWORD.
     *
     * Local-only (dummy/demo data):
     *   DevelopmentDataSeeder — factory-driven cities, stores, schedules,
     *   policies, employees, outsource workers, attendance history.
     */
    public function run(): void
    {
        $this->call(RolesAndPermissionsSeeder::class);

        $this->seedDashboardUsers();

        if (! app()->environment('local')) {
            return;
        }

        // Factory-driven demo data (cities, stores, schedules, policies,
        // employees, outsource workers, attendance history). Dev only.
        $this->call(DevelopmentDataSeeder::class);
    }

    /**
     * Initial dashboard login accounts — active in every environment.
     */
    protected function seedDashboardUsers(): void
    {
        $dashboardUsers = [
            ['name' => 'Super Admin', 'email' => 'superadmin@mito.co.id', 'role' => 'SUPER_ADMIN'],
            ['name' => 'Admin', 'email' => 'admin@mito.co.id', 'role' => 'ADMIN'],
            ['name' => 'User', 'email' => 'user@mito.co.id', 'role' => 'USER'],
        ];

        $password = (string) env('SEED_USER_PASSWORD', 'Mahakarya2026');

        foreach ($dashboardUsers as $dashboardUser) {
            $user = User::firstOrCreate(
                ['email' => $dashboardUser['email']],
                [
                    'name' => $dashboardUser['name'],
                    'password' => $password,
                ]
            );

            $user->assignRole($dashboardUser['role']);

            if (
                in_array($dashboardUser['role'], ['ADMIN', 'SUPER_ADMIN'], true)
            ) {
                $user->syncPermissions(Permission::all());
            }

            if (! in_array($dashboardUser['role'], ['ADMIN', 'SUPER_ADMIN'], true)) {
                Employee::updateOrCreate(
                    ['user_id' => $user->id],
                    [
                        'employee_code' => 'EMP-'.strtoupper(strtok($dashboardUser['email'], '@')),
                        'full_name' => $dashboardUser['name'],
                        'email' => $dashboardUser['email'],
                        'employment_status' => 'permanent',
                        'join_date' => now()->subYear()->toDateString(),
                    ],
                );
            }
        }
    }
}
