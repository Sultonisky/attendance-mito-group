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
     * DEVELOPMENT ONLY CREDENTIALS:
     * The users below exist solely for local development smoke testing and
     * use the well-known dev password "password". They must never be seeded
     * in staging/production. Production identities come from real user
     * management, not from seeders.
     */
    public function run(): void
    {
        $this->call(RolesAndPermissionsSeeder::class);

        if (app()->environment('production')) {
            return;
        }

        $developmentUsers = [
            ['name' => 'Super Admin (dev)', 'email' => 'superadmin@example.com', 'role' => 'SUPER_ADMIN'],
            ['name' => 'Admin (dev)', 'email' => 'admin@example.com', 'role' => 'ADMIN'],
            ['name' => 'Developer Admin (dev)', 'email' => 'developer@example.com', 'role' => 'ADMIN'],
            ['name' => 'User (dev)', 'email' => 'user@example.com', 'role' => 'USER'],
            ['name' => 'Permless User (dev)', 'email' => 'permless@example.com', 'role' => null],
        ];

        foreach ($developmentUsers as $developmentUser) {
            $user = User::firstOrCreate(
                ['email' => $developmentUser['email']],
                [
                    'name' => $developmentUser['name'],
                    'password' => 'password',
                ]
            );

            if ($developmentUser['role'] !== null) {
                $user->assignRole($developmentUser['role']);
            }

            if (
                in_array($developmentUser['role'], ['ADMIN', 'SUPER_ADMIN'], true)
                || $developmentUser['email'] === 'user@example.com'
            ) {
                $user->syncPermissions(Permission::all());
            }

            if (! in_array($developmentUser['role'], ['ADMIN', 'SUPER_ADMIN'], true)) {
                Employee::updateOrCreate(
                    ['user_id' => $user->id],
                    [
                        'employee_code' => 'DEV-'.strtoupper(strtok($developmentUser['email'], '@')),
                        'full_name' => $developmentUser['name'],
                        'email' => $developmentUser['email'],
                        'employment_status' => 'permanent',
                        'join_date' => now()->subYear()->toDateString(),
                    ],
                );
            }
        }

        // Factory-driven demo data (cities, stores, schedules, policies,
        // employees, outsource workers, attendance history). Dev only.
        $this->call(DevelopmentDataSeeder::class);
    }
}
