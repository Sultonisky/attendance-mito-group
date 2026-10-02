<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Exceptions\RoleDoesNotExist;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class InitialUserSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_seed_creates_initial_dashboard_accounts(): void
    {
        // Initial dashboard users are seeded in every environment (see
        // DatabaseSeeder) — no environment opt-in needed here. Dummy demo
        // data (DevelopmentDataSeeder) stays local-only and is skipped in
        // this testing environment.
        $this->seed(DatabaseSeeder::class);

        $admin = User::query()->where('email', 'admin@mito.co.id')->first();

        $this->assertNotNull($admin);
        $this->assertTrue($admin->hasRole('ADMIN'));
        $this->assertTrue($admin->can('dashboard.view'));
        $this->assertTrue($admin->can('employees.view'));
        $this->assertTrue($admin->can('monthly_recap.export'));
        $this->assertTrue($admin->can('outsource_attendance.view'));
        $this->assertTrue($admin->can('outsource_attendance.create'));
        $this->assertTrue($admin->can('outsource_attendance.update'));
        $this->assertTrue($admin->can('outsource_attendance.void'));
        // ADMIN must not access Users / Permissions / Audit Logs menus.
        $this->assertFalse($admin->can('user.view'));
        $this->assertFalse($admin->can('permission.view'));
        $this->assertFalse($admin->can('audit.view'));

        $superAdmin = User::query()->where('email', 'superadmin@mito.co.id')->first();
        $this->assertNotNull($superAdmin);
        $this->assertTrue($superAdmin->hasRole('SUPER_ADMIN'));
        $this->assertEquals(Permission::count(), $superAdmin->getAllPermissions()->count());
    }

    public function test_seed_creates_named_operator_accounts(): void
    {
        $this->seed(DatabaseSeeder::class);

        $hisar = User::query()->where('email', 'hisar.hesti@mito.co.id')->first();
        $this->assertNotNull($hisar);
        $this->assertSame('Hisar Hesti', $hisar->name);
        $this->assertTrue($hisar->hasRole('ADMIN'));
        $this->assertTrue($hisar->can('dashboard.view'));
        $this->assertTrue($hisar->can('employees.view'));
        $this->assertTrue($hisar->can('outsource_attendance.view'));
        $this->assertFalse($hisar->can('user.view'));
        $this->assertFalse($hisar->can('permission.view'));
        $this->assertFalse($hisar->can('audit.view'));
        $this->assertFalse($hisar->hasRole('SUPER_ADMIN'));

        $reginald = User::query()->where('email', 'reginald.hirawan@mito.co.id')->first();
        $this->assertNotNull($reginald);
        $this->assertSame('Reginald Hirawan', $reginald->name);
        $this->assertTrue($reginald->hasRole('SUPER_ADMIN'));
        $this->assertEquals(Permission::count(), $reginald->getAllPermissions()->count());
        $this->assertTrue($reginald->can('user.view'));
        $this->assertTrue($reginald->can('permission.view'));
        $this->assertTrue($reginald->can('audit.view'));
    }

    public function test_seed_does_not_link_dashboard_users_to_employees(): void
    {
        // Pre-existing employee with same email/code must stay unlinked by seed.
        \App\Models\Employee::query()->create([
            'employee_code' => 'EMP-USER',
            'full_name' => 'Legacy User',
            'email' => 'user@mito.co.id',
            'employment_status' => 'permanent',
            'join_date' => now()->subYears(2)->toDateString(),
            'user_id' => null,
        ]);

        $this->seed(DatabaseSeeder::class);
        $this->seed(DatabaseSeeder::class);

        $user = User::query()->where('email', 'user@mito.co.id')->first();
        $this->assertNotNull($user);
        $this->assertTrue($user->hasRole('USER'));

        $employee = \App\Models\Employee::query()->where('employee_code', 'EMP-USER')->first();
        $this->assertNotNull($employee);
        $this->assertNull($employee->user_id);
        $this->assertSame('Legacy User', $employee->full_name);

        $this->assertSame(
            0,
            \App\Models\Employee::query()->where('user_id', $user->id)->count(),
        );
    }

    public function test_reseed_preserves_account_changes_made_after_bootstrap(): void
    {
        $this->seed(DatabaseSeeder::class);

        // SUPER_ADMIN edits via the Users page between deploys.
        $hisar = User::query()->where('email', 'hisar.hesti@mito.co.id')->firstOrFail();
        $hisar->update(['name' => 'Hisar Hesti Updated', 'password' => 'NewSecret2026!']);
        $hisar->syncRoles(['USER']);
        $hisar->givePermissionTo('user.view');

        User::query()->where('email', 'admin@mito.co.id')->firstOrFail()->delete();

        $this->seed(DatabaseSeeder::class);

        $hisar = $hisar->fresh();
        $this->assertSame('Hisar Hesti Updated', $hisar->name);
        $this->assertTrue(Hash::check('NewSecret2026!', $hisar->password));
        $this->assertTrue($hisar->hasRole('USER'));
        $this->assertFalse($hisar->hasRole('ADMIN'));
        $this->assertTrue($hisar->hasDirectPermission('user.view'));

        $this->assertDatabaseMissing('users', ['email' => 'admin@mito.co.id']);
    }

    public function test_failed_bootstrap_rolls_back_all_accounts(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        // The last seeded account (user@) needs the USER role; removing it
        // makes the bootstrap fail after the first accounts were inserted.
        Role::findByName('USER')->delete();
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $seeder = new DatabaseSeeder;
        $method = new \ReflectionMethod($seeder, 'seedDashboardUsers');

        try {
            $method->invoke($seeder);
            $this->fail('Expected the bootstrap to fail on the missing USER role.');
        } catch (RoleDoesNotExist) {
            // expected
        }

        $this->assertSame(0, User::query()->count());
    }

    public function test_failed_bootstrap_still_leaves_break_glass_super_admin(): void
    {
        $seeder = new class extends DatabaseSeeder
        {
            protected function seedDashboardUsers(): void
            {
                throw new \RuntimeException('bootstrap failed');
            }
        };
        $seeder->setContainer(app());

        try {
            $seeder->run();
            $this->fail('Expected the bootstrap failure to propagate.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('bootstrap failed', $exception->getMessage());
        }

        $superAdmin = User::query()->where('email', 'superadmin@mito.co.id')->firstOrFail();
        $this->assertSame(1, User::query()->count());
        $this->assertSame('active', $superAdmin->status);
        $this->assertTrue($superAdmin->hasRole('SUPER_ADMIN'));
        $this->assertTrue(Hash::check('Mahakarya2026', $superAdmin->password));
    }

    public function test_reseed_restores_super_admin_when_all_were_demoted_or_deactivated(): void
    {
        $this->seed(DatabaseSeeder::class);

        $superAdmin = User::query()->where('email', 'superadmin@mito.co.id')->firstOrFail();
        $superAdmin->update(['status' => 'inactive', 'password' => 'ChangedByOwner2026!']);
        $superAdmin->syncRoles(['ADMIN']);

        User::query()->where('email', 'reginald.hirawan@mito.co.id')->firstOrFail()->syncRoles(['ADMIN']);

        $this->seed(DatabaseSeeder::class);

        $superAdmin = $superAdmin->fresh();
        $this->assertSame('active', $superAdmin->status);
        $this->assertTrue($superAdmin->hasRole('SUPER_ADMIN'));
        $this->assertFalse($superAdmin->hasRole('ADMIN'));
        // Existing password is kept — never reset to the known default.
        $this->assertTrue(Hash::check('ChangedByOwner2026!', $superAdmin->password));

        $this->assertFalse(
            User::query()->where('email', 'reginald.hirawan@mito.co.id')->firstOrFail()->hasRole('SUPER_ADMIN'),
        );
    }

    public function test_break_glass_does_not_recreate_deleted_super_admin_while_another_is_active(): void
    {
        $this->seed(DatabaseSeeder::class);

        User::query()->where('email', 'superadmin@mito.co.id')->firstOrFail()->delete();

        $this->seed(DatabaseSeeder::class);

        $this->assertDatabaseMissing('users', ['email' => 'superadmin@mito.co.id']);
    }
}
