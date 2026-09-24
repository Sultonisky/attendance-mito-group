<?php

namespace Tests\Feature\User;

use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
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

    private function superAdmin(): User
    {
        $user = User::factory()->create();
        $user->assignRole('SUPER_ADMIN');

        return $user;
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
        $user->syncPermissions(['dashboard.view', 'attendance.view']);

        $this->actingAs($this->superAdmin(), 'sanctum')
            ->getJson("/api/v1/users/{$user->id}/permissions")
            ->assertOk()
            ->assertJson([
                'success' => true,
            ])
            ->assertJsonCount(2, 'data');
    }

    public function test_admin_cannot_view_user_permissions(): void
    {
        $target = $this->admin();

        $this->actingAs($this->admin(), 'sanctum')
            ->getJson("/api/v1/users/{$target->id}/permissions")
            ->assertForbidden();
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

    public function test_super_admin_can_sync_user_permissions(): void
    {
        $user = $this->admin();

        $this->actingAs($this->superAdmin(), 'sanctum')
            ->postJson("/api/v1/users/{$user->id}/permissions", [
                'permissions' => ['dashboard.view', 'attendance.view', 'leave.view'],
            ])
            ->assertOk()
            ->assertJson([
                'success' => true,
            ])
            ->assertJsonCount(3, 'data');

        $this->assertTrue($user->fresh()->hasAllPermissions(['dashboard.view', 'attendance.view', 'leave.view']));
    }

    public function test_admin_cannot_sync_user_permissions(): void
    {
        $target = $this->admin();

        $this->actingAs($this->admin(), 'sanctum')
            ->postJson("/api/v1/users/{$target->id}/permissions", [
                'permissions' => ['dashboard.view'],
            ])
            ->assertForbidden();
    }

    public function test_requires_user_update_permission(): void
    {
        $user = User::factory()->create();
        $role = Role::findByName('USER');
        $role->syncPermissions(['dashboard.view', 'attendance.view']);
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

        $this->actingAs($this->superAdmin(), 'sanctum')
            ->postJson("/api/v1/users/{$user->id}/permissions", [
                'permissions' => ['nonexistent.permission'],
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('permissions.0');
    }

    public function test_sync_replaces_existing_permissions(): void
    {
        $user = $this->admin();
        $user->syncPermissions(['dashboard.view', 'attendance.view']);

        $this->actingAs($this->superAdmin(), 'sanctum')
            ->postJson("/api/v1/users/{$user->id}/permissions", [
                'permissions' => ['leave.view'],
            ])
            ->assertOk()
            ->assertJsonCount(1, 'data');

        $this->assertFalse($user->fresh()->hasDirectPermission('dashboard.view'));
        $this->assertTrue($user->fresh()->hasDirectPermission('leave.view'));
    }
}
