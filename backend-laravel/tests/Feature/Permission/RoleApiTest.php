<?php

namespace Tests\Feature\Permission;

use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class RoleApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    private function userWithRole(string $role): User
    {
        $user = User::factory()->create();
        $user->assignRole($role);

        return $user;
    }

    public function test_lists_roles_with_permissions_and_editability(): void
    {
        $this->userWithRole('ADMIN');

        $roles = collect($this->actingAs($this->userWithRole('SUPER_ADMIN'), 'sanctum')
            ->getJson('/api/v1/roles')
            ->assertOk()
            ->json('data'))->keyBy('name');

        $this->assertFalse($roles['SUPER_ADMIN']['editable']);
        $this->assertTrue($roles['ADMIN']['editable']);
        $this->assertSame(1, $roles['ADMIN']['users_count']);
        $this->assertContains('attendance.view', $roles['ADMIN']['permissions']);
        $this->assertNotContains('user.view', $roles['ADMIN']['permissions']);
    }

    public function test_template_edit_applies_to_new_users_only(): void
    {
        $actor = $this->userWithRole('SUPER_ADMIN');
        $existing = $this->userWithRole('ADMIN');

        $this->actingAs($actor, 'sanctum')
            ->putJson('/api/v1/roles/'.Role::findByName('ADMIN', 'web')->id.'/permissions', [
                'permissions' => ['dashboard.view', 'user.view'],
            ])
            ->assertOk()
            ->assertJsonPath('data.permissions', ['dashboard.view', 'user.view']);

        $existing = $existing->fresh();
        $this->assertTrue($existing->can('attendance.view'));
        $this->assertFalse($existing->can('user.view'));

        $new = $this->userWithRole('ADMIN');
        $this->assertEqualsCanonicalizing(['dashboard.view', 'user.view'], $new->getAllPermissions()->pluck('name')->all());

        $this->assertDatabaseHas('audit_logs', [
            'actor_id' => $actor->id,
            'action' => 'role.permissions_updated',
            'auditable_type' => Role::class,
        ]);
    }

    public function test_role_can_be_emptied(): void
    {
        $this->actingAs($this->userWithRole('SUPER_ADMIN'), 'sanctum')
            ->putJson('/api/v1/roles/'.Role::findByName('USER', 'web')->id.'/permissions', ['permissions' => []])
            ->assertOk()
            ->assertJsonPath('data.permissions', []);

        $this->assertCount(0, Role::findByName('USER', 'web')->permissions);
    }

    public function test_role_update_keeps_direct_user_grants(): void
    {
        $admin = $this->userWithRole('ADMIN');
        $admin->givePermissionTo('audit.view');

        $this->actingAs($this->userWithRole('SUPER_ADMIN'), 'sanctum')
            ->putJson('/api/v1/roles/'.Role::findByName('ADMIN', 'web')->id.'/permissions', ['permissions' => ['dashboard.view']])
            ->assertOk();

        $this->assertTrue($admin->fresh()->hasDirectPermission('audit.view'));
    }

    public function test_super_admin_role_cannot_be_edited(): void
    {
        $this->actingAs($this->userWithRole('SUPER_ADMIN'), 'sanctum')
            ->putJson('/api/v1/roles/'.Role::findByName('SUPER_ADMIN', 'web')->id.'/permissions', ['permissions' => ['dashboard.view']])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('role');

        $this->assertCount(0, Role::findByName('SUPER_ADMIN', 'web')->permissions);
    }

    public function test_validates_permission_names(): void
    {
        $this->actingAs($this->userWithRole('SUPER_ADMIN'), 'sanctum')
            ->putJson('/api/v1/roles/'.Role::findByName('ADMIN', 'web')->id.'/permissions', ['permissions' => ['nope.nope']])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('permissions.0');
    }

    public function test_admin_cannot_view_or_edit_roles(): void
    {
        $admin = $this->userWithRole('ADMIN');

        $this->actingAs($admin, 'sanctum')->getJson('/api/v1/roles')->assertForbidden();
        $this->actingAs($admin, 'sanctum')
            ->putJson('/api/v1/roles/'.Role::findByName('ADMIN', 'web')->id.'/permissions', ['permissions' => ['user.view']])
            ->assertForbidden();

        $this->assertFalse($admin->fresh()->can('user.view'));
    }

    public function test_reseeding_preserves_role_edits(): void
    {
        $admin = Role::findByName('ADMIN', 'web');
        $admin->syncPermissions(['dashboard.view', 'user.view']);

        $this->seed(RolesAndPermissionsSeeder::class);

        $this->assertEqualsCanonicalizing(
            ['dashboard.view', 'user.view'],
            $admin->fresh()->permissions->pluck('name')->all(),
        );
    }

    public function test_reseeding_grants_newly_introduced_default_permissions(): void
    {
        $holder = $this->userWithRole('ADMIN');
        $holder->syncPermissions(['dashboard.view']);
        Role::findByName('ADMIN', 'web')->syncPermissions(['dashboard.view']);
        // Simulate a permission shipped in a new release.
        Permission::findByName('attendance.view')->delete();

        $this->seed(RolesAndPermissionsSeeder::class);

        $this->assertEqualsCanonicalizing(
            ['dashboard.view', 'attendance.view'],
            Role::findByName('ADMIN', 'web')->fresh()->permissions->pluck('name')->all(),
        );
        $this->assertEqualsCanonicalizing(
            ['dashboard.view', 'attendance.view'],
            $holder->fresh()->getAllPermissions()->pluck('name')->all(),
        );
    }

    public function test_reseeding_keeps_per_user_removals(): void
    {
        $admin = $this->userWithRole('ADMIN');
        $admin->revokePermissionTo('attendance.view');

        $this->seed(RolesAndPermissionsSeeder::class);

        $this->assertFalse($admin->fresh()->can('attendance.view'));
    }
}
