<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class DevAdminSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_seed_creates_developer_admin_with_all_permissions(): void
    {
        $this->seed(DatabaseSeeder::class);

        $user = User::query()->where('email', 'developer@example.com')->first();

        $this->assertNotNull($user);
        $this->assertTrue($user->hasRole('ADMIN'));
        $this->assertEquals(Permission::count(), $user->getAllPermissions()->count());
        $this->assertTrue($user->can('dashboard.view'));
        $this->assertTrue($user->can('employees.view'));
        $this->assertTrue($user->can('monthly_recap.export'));
    }
}
