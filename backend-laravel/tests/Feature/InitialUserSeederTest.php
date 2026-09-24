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

        $user = User::query()->where('email', 'admin@mito.co.id')->first();

        $this->assertNotNull($user);
        $this->assertTrue($user->hasRole('ADMIN'));
        $this->assertEquals(Permission::count(), $user->getAllPermissions()->count());
        $this->assertTrue($user->can('dashboard.view'));
        $this->assertTrue($user->can('employees.view'));
        $this->assertTrue($user->can('monthly_recap.export'));
        $this->assertTrue($user->can('outsource_attendance.view'));
        $this->assertTrue($user->can('outsource_attendance.create'));
        $this->assertTrue($user->can('outsource_attendance.update'));
        $this->assertTrue($user->can('outsource_attendance.void'));

        $superAdmin = User::query()->where('email', 'superadmin@mito.co.id')->first();
        $this->assertNotNull($superAdmin);
        $this->assertTrue($superAdmin->hasRole('SUPER_ADMIN'));
        $this->assertEquals(Permission::count(), $superAdmin->getAllPermissions()->count());
    }
}