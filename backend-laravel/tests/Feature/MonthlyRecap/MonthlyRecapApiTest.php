<?php

namespace Tests\Feature\MonthlyRecap;

use App\Models\Employee;
use App\Models\MonthlyRecap;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MonthlyRecapApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    private function makeUser(string $role = 'USER', array $overrides = []): User
    {
        $user = User::factory()->create($overrides);
        $user->assignRole($role);

        return $user;
    }

    private function makeEmployee(array $overrides = []): Employee
    {
        return Employee::factory()->create($overrides);
    }

    private function makeActiveUserAndEmployee(string $role = 'USER'): array
    {
        $user = $this->makeUser($role);
        $employee = $this->makeEmployee();
        $employee->update(['user_id' => $user->id]);

        return [$user, $employee];
    }

    public function test_index_requires_auth(): void
    {
        $this->getJson('/api/v1/monthly-recaps')->assertUnauthorized();
    }

    public function test_user_sees_own_recaps(): void
    {
        [$user, $employee] = $this->makeActiveUserAndEmployee();

        MonthlyRecap::create([
            'employee_id' => $employee->id,
            'period' => '2026-09',
            'status' => 'draft',
        ]);

        $this->actingAs($user, 'sanctum')->getJson('/api/v1/monthly-recaps')
            ->assertOk()
            ->assertJsonCount(1, 'data');
    }

    public function test_admin_sees_all_recaps_with_filter(): void
    {
        [$userA, $employeeA] = $this->makeActiveUserAndEmployee();
        [$userB, $employeeB] = $this->makeActiveUserAndEmployee();

        MonthlyRecap::create(['employee_id' => $employeeA->id, 'period' => '2026-09', 'status' => 'draft']);
        MonthlyRecap::create(['employee_id' => $employeeB->id, 'period' => '2026-09', 'status' => 'draft']);

        $admin = $this->makeUser('ADMIN');
        $admin->givePermissionTo('monthly_recap.view');
        Employee::factory()->create(['user_id' => $admin->id]);

        $this->actingAs($admin, 'sanctum')->getJson('/api/v1/monthly-recaps')
            ->assertOk()
            ->assertJsonCount(2, 'data');
    }

    public function test_admin_without_employee_profile_can_list_recaps(): void
    {
        [$userA, $employeeA] = $this->makeActiveUserAndEmployee();
        MonthlyRecap::create(['employee_id' => $employeeA->id, 'period' => '2026-09', 'status' => 'draft']);

        $admin = $this->makeUser('ADMIN');
        $admin->givePermissionTo('monthly_recap.view');

        $this->actingAs($admin, 'sanctum')->getJson('/api/v1/monthly-recaps')
            ->assertOk()
            ->assertJsonCount(1, 'data');
    }

    public function test_show_ownership_check(): void
    {
        [$userA, $employeeA] = $this->makeActiveUserAndEmployee();
        [$userB, $employeeB] = $this->makeActiveUserAndEmployee();

        $recap = MonthlyRecap::create(['employee_id' => $employeeA->id, 'period' => '2026-09', 'status' => 'draft']);

        $this->actingAs($userB, 'sanctum')->getJson("/api/v1/monthly-recaps/{$recap->id}")
            ->assertStatus(404);
    }

    public function test_unauthorized_access_returns_404(): void
    {
        $recap = MonthlyRecap::factory()->create();

        $this->getJson("/api/v1/monthly-recaps/{$recap->id}")->assertUnauthorized();
    }
}
