<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Deterministic, idempotent RBAC foundation.
 *
 * - Roles: SUPER_ADMIN, ADMIN, USER.
 * - Permissions: minimal module.action set used to prove RBAC. Business
 *   module permissions are added in their own phases.
 *
 * Re-running this seeder never duplicates roles or permissions.
 */
class RolesAndPermissionsSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Minimal permission foundation for Phase 3 (module.action convention).
     *
     * @var list<string>
     */
    protected const PERMISSIONS = [
        'dashboard.view',
        'employees.view',
        'penalty.view',
        'penalty.create',
        'penalty.adjust',
        'penalty.void',
    ];

    /**
     * Explicit role -> permission assignments.
     *
     * SUPER_ADMIN is intentionally NOT granted explicit permissions; it
     * bypasses permission checks centrally via Gate::before.
     *
     * @var array<string, list<string>>
     */
    protected const ROLE_PERMISSIONS = [
        'ADMIN' => [
            'dashboard.view',
            'employees.view',
            'penalty.view',
            'penalty.create',
            'penalty.adjust',
            'penalty.void',
        ],
        'USER' => [
            'dashboard.view',
            'penalty.view',
        ],
    ];

    public function run(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        foreach (self::PERMISSIONS as $permission) {
            Permission::firstOrCreate(['name' => $permission]);
        }

        Role::firstOrCreate(['name' => 'SUPER_ADMIN']);
        Role::firstOrCreate(['name' => 'ADMIN'])
            ->syncPermissions(self::ROLE_PERMISSIONS['ADMIN']);
        Role::firstOrCreate(['name' => 'USER'])
            ->syncPermissions(self::ROLE_PERMISSIONS['USER']);

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
}
