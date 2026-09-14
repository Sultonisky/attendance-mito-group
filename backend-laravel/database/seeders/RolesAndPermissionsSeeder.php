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
        'employees.manage-faces',
        'leave.view',
        'leave.create',
        'leave.approve',
        'leave.reject',
        'leave.cancel',
        'overtime.view',
        'overtime.create',
        'overtime.approve',
        'overtime.reject',
        'overtime.cancel',
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
            'leave.view',
            'leave.approve',
            'leave.reject',
            'leave.cancel',
            'overtime.view',
            'overtime.approve',
            'overtime.reject',
            'overtime.cancel',
        ],
        'USER' => [
            'dashboard.view',
            'leave.view',
            'leave.create',
            'leave.cancel',
            'overtime.view',
            'overtime.create',
            'overtime.cancel',
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
