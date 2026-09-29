<?php

namespace Tests\Feature\Permission;

use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class CopyRolePermissionsMigrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_copies_current_role_access_onto_each_user(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $admin = Role::findByName('ADMIN', 'web');

        // Pre-migration state: role attached without the template copy.
        $hisar = User::factory()->create();
        $hisar->roles()->attach($admin);
        $hisar->givePermissionTo('user.view');
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->assertFalse($hisar->fresh()->can('attendance.view'));

        $migration = require database_path('migrations/2026_09_29_000002_copy_role_permissions_to_users.php');
        $migration->up();
        $migration->up();

        $expected = [...$admin->permissions->pluck('name')->all(), 'user.view'];
        $this->assertEqualsCanonicalizing($expected, $hisar->fresh()->getAllPermissions()->pluck('name')->all());
        $this->assertTrue($hisar->fresh()->can('attendance.view'));
    }
}
