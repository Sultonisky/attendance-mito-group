<?php

namespace Tests\Feature\Employee;

use App\Enums\EmploymentStatus;
use App\Models\Employee;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class EmployeeApiTest extends TestCase
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
    // Index
    // ========================

    public function test_returns_employee_list(): void
    {
        Employee::factory()->count(3)->create();

        $this->actingAs($this->admin(), 'sanctum')
            ->getJson('/api/v1/employees')
            ->assertOk()
            ->assertJson([
                'success' => true,
            ])
            ->assertJsonCount(3, 'data');
    }

    public function test_requires_employees_view_permission(): void
    {
        $user = User::factory()->create();
        $role = Role::findByName('USER');
        $role->syncPermissions(['dashboard.view']);
        $user->assignRole('USER');

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/employees')
            ->assertForbidden();
    }

    // ========================
    // Store
    // ========================

    public function test_admin_can_create_employee(): void
    {
        $this->actingAs($this->admin(), 'sanctum')
            ->postJson('/api/v1/employees', [
                'employee_code' => 'EMP001',
                'full_name' => 'John Doe',
                'email' => 'john@example.com',
                'employment_status' => EmploymentStatus::Permanent->value,
                'join_date' => '2025-01-01',
            ])
            ->assertCreated()
            ->assertJson([
                'success' => true,
                'data' => [
                    'employee_code' => 'EMP001',
                    'full_name' => 'John Doe',
                ],
            ]);

        $this->assertDatabaseHas('employees', ['employee_code' => 'EMP001']);
    }

    public function test_requires_employees_create_permission(): void
    {
        $user = User::factory()->create();
        $role = Role::findByName('USER');
        $role->syncPermissions(['dashboard.view', 'employees.view']);
        $user->assignRole('USER');

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/employees', [
                'employee_code' => 'EMP001',
                'full_name' => 'John Doe',
                'employment_status' => EmploymentStatus::Permanent->value,
                'join_date' => '2025-01-01',
            ])
            ->assertForbidden();
    }

    // ========================
    // Show
    // ========================

    public function test_returns_single_employee(): void
    {
        $employee = Employee::factory()->create();

        $this->actingAs($this->admin(), 'sanctum')
            ->getJson("/api/v1/employees/{$employee->id}")
            ->assertOk()
            ->assertJson([
                'success' => true,
                'data' => [
                    'id' => $employee->id,
                    'employee_code' => $employee->employee_code,
                ],
            ]);
    }

    // ========================
    // Update
    // ========================

    public function test_admin_can_update_employee(): void
    {
        $employee = Employee::factory()->create();

        $this->actingAs($this->admin(), 'sanctum')
            ->putJson("/api/v1/employees/{$employee->id}", [
                'full_name' => 'Jane Doe',
                'employment_status' => EmploymentStatus::Permanent->value,
                'join_date' => '2025-01-01',
            ])
            ->assertOk()
            ->assertJson([
                'success' => true,
                'data' => [
                    'full_name' => 'Jane Doe',
                ],
            ]);

        $this->assertDatabaseHas('employees', [
            'id' => $employee->id,
            'full_name' => 'Jane Doe',
        ]);
    }

    public function test_requires_employees_update_permission(): void
    {
        $employee = Employee::factory()->create();
        $user = User::factory()->create();
        $role = Role::findByName('USER');
        $role->syncPermissions(['dashboard.view', 'employees.view']);
        $user->assignRole('USER');

        $this->actingAs($user, 'sanctum')
            ->putJson("/api/v1/employees/{$employee->id}", [
                'full_name' => 'Jane Doe',
                'employment_status' => EmploymentStatus::Permanent->value,
                'join_date' => '2025-01-01',
            ])
            ->assertForbidden();
    }

    // ========================
    // Destroy
    // ========================

    public function test_admin_can_delete_employee(): void
    {
        $employee = Employee::factory()->create();

        $this->actingAs($this->admin(), 'sanctum')
            ->deleteJson("/api/v1/employees/{$employee->id}")
            ->assertOk()
            ->assertJson(['success' => true]);

        $this->assertSoftDeleted('employees', ['id' => $employee->id]);
    }

    public function test_requires_employees_delete_permission(): void
    {
        $employee = Employee::factory()->create();
        $user = User::factory()->create();
        $role = Role::findByName('USER');
        $role->syncPermissions(['dashboard.view', 'employees.view']);
        $user->assignRole('USER');

        $this->actingAs($user, 'sanctum')
            ->deleteJson("/api/v1/employees/{$employee->id}")
            ->assertForbidden();
    }
}
