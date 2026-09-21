<?php

namespace Tests\Feature\User;

use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class UserPermissionsApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    private function admin(): User
    {
        $user = User::factory()->create();
        $user->assignRole('ADMIN');

        return $user;
    }

    // ========================
    // List user permissions
    // ========================

    public function test_returns_user_permissions(): void
    {
        $user = $this->admin();
        $user->syncPermissions(['dashboard.view', 'user.view']);

        $this->actingAs($this->admin(), 'sanctum')
            ->getJson("/api/v1/users/{$user->id}/permissions")
            ->assertOk()
            ->assertJson([
                'success' => true,
            ])
            ->assertJsonCount(2, 'data');
    }

    public function test_requires_user_view_permission(): void
    {
        $user = User::factory()->create();
        $role = Role::findByName('USER');
        $role->syncPermissions(['dashboard.view']);
        $user->assignRole('USER');

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/users/1/permissions')
            ->assertForbidden();
    }

    // ========================
    // Sync user permissions
    // ========================

    public function test_admin_can_sync_user_permissions(): void
    {
        $user = $this->admin();

        $this->actingAs($this->admin(), 'sanctum')
            ->postJson("/api/v1/users/{$user->id}/permissions", [
                'permissions' => ['dashboard.view', 'user.view', 'permission.view'],
            ])
            ->assertOk()
            ->assertJson([
                'success' => true,
            ])
            ->assertJsonCount(3, 'data');

        $this->assertTrue($user->fresh()->hasAllPermissions(['dashboard.view', 'user.view', 'permission.view']));
    }

    public function test_requires_user_update_permission(): void
    {
        $user = User::factory()->create();
        $role = Role::findByName('USER');
        $role->syncPermissions(['dashboard.view', 'user.view']);
        $user->assignRole('USER');

        $this->actingAs($user, 'sanctum')
            ->postJson("/api/v1/users/{$user->id}/permissions", [
                'permissions' => ['dashboard.view'],
            ])
            ->assertForbidden();
    }

    public function test_validates_permissions_exist(): void
    {
        $user = $this->admin();

        $this->actingAs($this->admin(), 'sanctum')
            ->postJson("/api/v1/users/{$user->id}/permissions", [
                'permissions' => ['nonexistent.permission'],
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('permissions.0');
    }

    public function test_sync_replaces_existing_permissions(): void
    {
        $user = $this->admin();
        $user->syncPermissions(['dashboard.view', 'user.view']);

        $this->actingAs($this->admin(), 'sanctum')
            ->postJson("/api/v1/users/{$user->id}/permissions", [
                'permissions' => ['permission.view'],
            ])
            ->assertOk()
            ->assertJsonCount(1, 'data');

        $this->assertFalse($user->fresh()->hasDirectPermission('dashboard.view'));
        $this->assertTrue($user->fresh()->hasDirectPermission('permission.view'));
    }
}
