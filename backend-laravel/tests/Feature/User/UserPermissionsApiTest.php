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

    public function test_new_user_receives_role_template(): void
    {
        $user = $this->admin();

        $data = $this->actingAs($this->superAdmin(), 'sanctum')
            ->getJson("/api/v1/users/{$user->id}/permissions")
            ->assertOk()
            ->assertJsonPath('role', 'ADMIN')
            ->assertJsonPath('super_admin_bypass', false)
            ->json('data');

        $this->assertEqualsCanonicalizing(
            Role::findByName('ADMIN', 'web')->permissions->pluck('name')->all(),
            $data,
        );
    }

    public function test_role_permissions_alone_grant_nothing(): void
    {
        $user = $this->admin();
        $user->syncPermissions([]);

        $this->assertTrue(Role::findByName('ADMIN', 'web')->hasPermissionTo('attendance.view'));
        $this->assertFalse($user->fresh()->can('attendance.view'));
        $this->assertCount(0, $user->fresh()->getAllPermissions());
    }

    public function test_super_admin_target_reports_bypass_with_all_permissions(): void
    {
        $target = $this->superAdmin();

        $response = $this->actingAs($this->superAdmin(), 'sanctum')
            ->getJson("/api/v1/users/{$target->id}/permissions")
            ->assertOk()
            ->assertJsonPath('role', 'SUPER_ADMIN')
            ->assertJsonPath('super_admin_bypass', true);

        $this->assertCount(Permission::count(), $response->json('data'));
    }

    // ========================
    // Edit user permissions
    // ========================

    public function test_users_with_same_role_can_have_different_access(): void
    {
        $actor = $this->superAdmin();
        $hisar = $this->admin();
        $other = $this->admin();

        $this->actingAs($actor, 'sanctum')
            ->putJson("/api/v1/users/{$hisar->id}/permissions", [
                'permissions' => ['dashboard.view', 'outsource_attendance.view', 'user.view'],
            ])
            ->assertOk()
            ->assertJsonPath('data', ['dashboard.view', 'outsource_attendance.view', 'user.view']);

        $hisar = $hisar->fresh();
        $this->assertFalse($hisar->can('attendance.view'));
        $this->assertFalse($hisar->can('monthly_recap.view'));
        $this->assertTrue($hisar->can('user.view'));

        $other = $other->fresh();
        $this->assertTrue($other->can('attendance.view'));
        $this->assertFalse($other->can('user.view'));

        $this->assertDatabaseHas('audit_logs', [
            'actor_id' => $actor->id,
            'action' => 'user.permissions_updated',
            'auditable_id' => $hisar->id,
        ]);
    }

    public function test_super_admin_permissions_cannot_be_edited(): void
    {
        $target = $this->superAdmin();

        $this->actingAs($this->superAdmin(), 'sanctum')
            ->putJson("/api/v1/users/{$target->id}/permissions", ['permissions' => []])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('user');
    }

    public function test_editing_user_permissions_requires_permission_update(): void
    {
        $actor = User::factory()->create();
        Role::findByName('USER', 'web')->syncPermissions(['dashboard.view', 'user.view', 'user.update']);
        $actor->assignRole('USER');
        $target = $this->admin();

        $this->actingAs($actor, 'sanctum')
            ->putJson("/api/v1/users/{$target->id}/permissions", ['permissions' => ['audit.view']])
            ->assertForbidden();

        $this->assertFalse($target->fresh()->can('audit.view'));
    }

    public function test_role_change_resets_permissions_to_new_template(): void
    {
        $user = $this->admin();
        $user->givePermissionTo('user.view');

        $this->actingAs($this->superAdmin(), 'sanctum')
            ->putJson("/api/v1/users/{$user->id}", ['role' => 'USER'])
            ->assertOk();

        $this->assertEqualsCanonicalizing(
            Role::findByName('USER', 'web')->permissions->pluck('name')->all(),
            $user->fresh()->getAllPermissions()->pluck('name')->all(),
        );
    }

    public function test_saving_user_with_same_role_keeps_custom_permissions(): void
    {
        $user = $this->admin();
        $user->syncPermissions(['dashboard.view', 'user.view']);

        $this->actingAs($this->superAdmin(), 'sanctum')
            ->putJson("/api/v1/users/{$user->id}", ['name' => 'Renamed', 'role' => 'ADMIN'])
            ->assertOk();

        $this->assertEqualsCanonicalizing(
            ['dashboard.view', 'user.view'],
            $user->fresh()->getAllPermissions()->pluck('name')->all(),
        );
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

    public function test_user_permissions_endpoint_is_read_only(): void
    {
        $user = $this->admin();

        $this->actingAs($this->superAdmin(), 'sanctum')
            ->postJson("/api/v1/users/{$user->id}/permissions", [
                'permissions' => ['user.view'],
            ])
            ->assertStatus(405);

        $this->assertFalse($user->fresh()->hasDirectPermission('user.view'));
    }
}
