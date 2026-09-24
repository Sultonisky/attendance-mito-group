<?php

namespace Tests\Feature\Permission;

use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class PermissionApiTest extends TestCase
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
    // Index
    // ========================

    public function test_returns_permissions_list(): void
    {
        $this->actingAs($this->superAdmin(), 'sanctum')
            ->getJson('/api/v1/permissions?per_page=100')
            ->assertOk()
            ->assertJson([
                'success' => true,
            ])
            ->assertJsonCount(Permission::count(), 'data');
    }

    public function test_admin_cannot_view_permissions(): void
    {
        $this->actingAs($this->admin(), 'sanctum')
            ->getJson('/api/v1/permissions')
            ->assertForbidden();
    }

    public function test_requires_permission_view(): void
    {
        $user = User::factory()->create();
        $role = Role::findByName('USER');
        $role->syncPermissions(['dashboard.view']);
        $user->assignRole('USER');

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/permissions')
            ->assertForbidden();
    }

    // ========================
    // Store
    // ========================

    public function test_super_admin_can_create_permission(): void
    {
        $this->actingAs($this->superAdmin(), 'sanctum')
            ->postJson('/api/v1/permissions', [
                'name' => 'test.permission',
            ])
            ->assertCreated()
            ->assertJson([
                'success' => true,
                'data' => [
                    'name' => 'test.permission',
                ],
            ]);

        $this->assertDatabaseHas('permissions', ['name' => 'test.permission']);
    }

    public function test_admin_cannot_create_permission(): void
    {
        $this->actingAs($this->admin(), 'sanctum')
            ->postJson('/api/v1/permissions', [
                'name' => 'test.permission',
            ])
            ->assertForbidden();
    }

    public function test_requires_permission_create(): void
    {
        $user = User::factory()->create();
        $role = Role::findByName('USER');
        $role->syncPermissions(['dashboard.view', 'permission.view']);
        $user->assignRole('USER');

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/permissions', [
                'name' => 'test.permission',
            ])
            ->assertForbidden();
    }

    public function test_validate_unique_name(): void
    {
        $this->actingAs($this->superAdmin(), 'sanctum')
            ->postJson('/api/v1/permissions', [
                'name' => 'dashboard.view',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('name');
    }

    // ========================
    // Show
    // ========================

    public function test_returns_single_permission(): void
    {
        $permission = Permission::first();

        $this->actingAs($this->superAdmin(), 'sanctum')
            ->getJson("/api/v1/permissions/{$permission->id}")
            ->assertOk()
            ->assertJson([
                'success' => true,
                'data' => [
                    'id' => $permission->id,
                    'name' => $permission->name,
                ],
            ]);
    }

    // ========================
    // Update
    // ========================

    public function test_super_admin_can_update_permission(): void
    {
        $permission = Permission::where('name', 'dashboard.view')->first();

        $this->actingAs($this->superAdmin(), 'sanctum')
            ->putJson("/api/v1/permissions/{$permission->id}", [
                'name' => 'dashboard.updated',
            ])
            ->assertOk()
            ->assertJson([
                'success' => true,
                'data' => [
                    'name' => 'dashboard.updated',
                ],
            ]);

        $this->assertDatabaseHas('permissions', [
            'id' => $permission->id,
            'name' => 'dashboard.updated',
        ]);
    }

    public function test_requires_permission_update(): void
    {
        $permission = Permission::first();
        $user = User::factory()->create();
        $role = Role::findByName('USER');
        $role->syncPermissions(['dashboard.view', 'permission.view']);
        $user->assignRole('USER');

        $this->actingAs($user, 'sanctum')
            ->putJson("/api/v1/permissions/{$permission->id}", [
                'name' => 'updated.name',
            ])
            ->assertForbidden();
    }

    // ========================
    // Users for a permission
    // ========================

    public function test_returns_users_with_permission(): void
    {
        $permission = Permission::where('name', 'dashboard.view')->first();
        $user = $this->admin();
        $user->syncPermissions([$permission->name]);

        $this->actingAs($this->superAdmin(), 'sanctum')
            ->getJson("/api/v1/permissions/{$permission->id}/users")
            ->assertOk()
            ->assertJson([
                'success' => true,
            ])
            ->assertJsonStructure([
                'success',
                'data' => [
                    '*' => ['id', 'name', 'email', 'status'],
                ],
            ]);
    }

    public function test_returns_users_with_permission_through_role(): void
    {
        $permission = Permission::where('name', 'dashboard.view')->first();
        $user = User::factory()->create();
        $user->assignRole('ADMIN');

        $this->actingAs($this->superAdmin(), 'sanctum')
            ->getJson("/api/v1/permissions/{$permission->id}/users")
            ->assertOk()
            ->assertJson([
                'success' => true,
            ])
            ->assertJsonStructure([
                'success',
                'data' => [
                    '*' => ['id', 'name', 'email', 'status'],
                ],
            ]);
    }

    public function test_requires_permission_view_for_users_list(): void
    {
        $permission = Permission::first();
        $user = User::factory()->create();
        $role = Role::findByName('USER');
        $role->syncPermissions(['dashboard.view']);
        $user->assignRole('USER');

        $this->actingAs($user, 'sanctum')
            ->getJson("/api/v1/permissions/{$permission->id}/users")
            ->assertForbidden();
    }

    // ========================
    // Destroy
    // ========================

    public function test_super_admin_can_delete_permission(): void
    {
        $permission = Permission::create(['name' => 'test.delete', 'guard_name' => 'web']);

        $this->actingAs($this->superAdmin(), 'sanctum')
            ->deleteJson("/api/v1/permissions/{$permission->id}")
            ->assertOk()
            ->assertJson(['success' => true]);

        $this->assertDatabaseMissing('permissions', ['id' => $permission->id]);
    }

    public function test_requires_permission_delete(): void
    {
        $permission = Permission::first();
        $user = User::factory()->create();
        $role = Role::findByName('USER');
        $role->syncPermissions(['dashboard.view', 'permission.view']);
        $user->assignRole('USER');

        $this->actingAs($user, 'sanctum')
            ->deleteJson("/api/v1/permissions/{$permission->id}")
            ->assertForbidden();
    }
}
