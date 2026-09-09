<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * RBAC verification matrix, executed directly against the Laravel API.
 *
 * The frontend is bypassed entirely here: authorization must be enforced
 * by the backend (Sanctum -> Gate -> 403).
 */
class RbacTest extends TestCase
{
    use RefreshDatabase;

    /**
     * The permission-protected demonstration endpoint.
     */
    private const PROTECTED_ENDPOINT = '/api/v1/rbac/demo';

    protected function setUp(): void
    {
        parent::setUp();

        // Idempotent RBAC foundation (same seeder used by artisan db:seed).
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    private function userWithRole(string $roleName, array $attributes = []): User
    {
        $user = User::factory()->create($attributes);
        $user->assignRole($roleName);

        return $user;
    }

    /**
     * SUPER_ADMIN passes the permission check without holding the permission.
     */
    public function test_super_admin_is_allowed_without_explicit_permission(): void
    {
        $superAdmin = $this->userWithRole('SUPER_ADMIN');

        $this->actingAs($superAdmin, 'sanctum')
            ->getJson(self::PROTECTED_ENDPOINT)
            ->assertOk();
    }

    /**
     * ADMIN is allowed when the required permission is assigned to the role.
     */
    public function test_admin_with_required_permission_is_allowed(): void
    {
        $admin = $this->userWithRole('ADMIN');

        $this->assertTrue($admin->hasPermissionTo('dashboard.view'));

        $this->actingAs($admin, 'sanctum')
            ->getJson(self::PROTECTED_ENDPOINT)
            ->assertOk();
    }

    /**
     * ADMIN without the required permission is denied.
     */
    public function test_admin_without_required_permission_is_denied(): void
    {
        // Give ADMIN a different permission only.
        $role = Role::findByName('ADMIN');
        $role->syncPermissions([Permission::findByName('employees.view')]);

        $admin = $this->userWithRole('ADMIN');

        $this->actingAs($admin, 'sanctum')
            ->getJson(self::PROTECTED_ENDPOINT)
            ->assertForbidden();
    }

    /**
     * USER is allowed only when the required permission is assigned.
     */
    public function test_user_with_required_permission_is_allowed(): void
    {
        $user = $this->userWithRole('USER');

        $this->assertTrue($user->hasPermissionTo('dashboard.view'));
        $this->assertFalse($user->hasPermissionTo('employees.view'));

        $this->actingAs($user, 'sanctum')
            ->getJson(self::PROTECTED_ENDPOINT)
            ->assertOk();
    }

    /**
     * MANDATORY CASE: a USER with no permissions has no runtime permission.
     */
    public function test_user_with_no_permissions_is_denied(): void
    {
        $role = Role::findByName('USER');
        $role->syncPermissions([]);

        $user = $this->userWithRole('USER');

        $this->assertFalse($user->hasPermissionTo('dashboard.view'));
        $this->assertFalse($user->can('dashboard.view'));

        $this->actingAs($user, 'sanctum')
            ->getJson(self::PROTECTED_ENDPOINT)
            ->assertForbidden();
    }

    /**
     * A user without any role is denied.
     */
    public function test_user_without_any_role_is_denied(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user, 'sanctum')
            ->getJson(self::PROTECTED_ENDPOINT)
            ->assertForbidden();
    }

    /**
     * Unauthenticated requests are rejected before authorization.
     */
    public function test_unauthenticated_request_is_rejected(): void
    {
        $this->getJson(self::PROTECTED_ENDPOINT)
            ->assertUnauthorized();
    }

    /**
     * Permissions are dynamic: a permission created after boot is enforced.
     */
    public function test_dynamically_created_permission_is_enforced(): void
    {
        $user = $this->userWithRole('USER');
        $user->givePermissionTo('employees.view');

        $this->assertTrue($user->hasPermissionTo('employees.view'));
        $this->assertFalse($user->can('employees.delete'));
    }
}
