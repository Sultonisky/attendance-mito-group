<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
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
}
