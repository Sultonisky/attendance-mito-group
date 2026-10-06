<?php

namespace Tests\Feature\Employee;

use App\Enums\EmploymentStatus;
use App\Models\Employee;
use App\Models\EmployeeWorkLocation;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EmployeeWorkLocationAssignmentTest extends TestCase
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

    /**
     * @return array<string, mixed>
     */
    private function employeePayload(array $overrides = []): array
    {
        return array_merge([
            'employee_code' => 'EMP-WL-1',
            'full_name' => 'Budi Santoso',
            'employment_status' => EmploymentStatus::Permanent->value,
            'join_date' => '2025-01-01',
        ], $overrides);
    }

    private function assignmentStatus(Employee $employee, EmployeeWorkLocation $location): ?string
    {
        return $employee->workLocations()
            ->where('employee_work_locations.id', $location->id)
            ->first()
            ?->pivot
            ->status;
    }

    public function test_create_employee_with_work_locations(): void
    {
        $ho = EmployeeWorkLocation::factory()->create(['name' => 'Head Office', 'city' => 'Jakarta']);
        $branch = EmployeeWorkLocation::factory()->create(['name' => 'Cabang Bandung', 'city' => 'Bandung']);

        $response = $this->actingAs($this->admin(), 'sanctum')
            ->postJson('/api/v1/employees', $this->employeePayload([
                'nik' => '1234567890123456',
                'work_location_ids' => [$ho->id, $branch->id],
            ]))
            ->assertCreated()
            ->assertJsonCount(2, 'data.work_locations');

        $employee = Employee::findOrFail($response->json('data.id'));

        $this->assertSame('active', $this->assignmentStatus($employee, $ho));
        $this->assertSame('active', $this->assignmentStatus($employee, $branch));
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'employee.work_locations_synced',
            'auditable_id' => $employee->id,
        ]);
    }

    public function test_create_employee_without_work_locations_still_allowed(): void
    {
        $this->actingAs($this->admin(), 'sanctum')
            ->postJson('/api/v1/employees', $this->employeePayload())
            ->assertCreated()
            ->assertJsonPath('data.work_locations', []);

        $this->assertDatabaseCount('employee_work_location_assignments', 0);
    }

    public function test_rejects_duplicate_and_deleted_work_locations(): void
    {
        $location = EmployeeWorkLocation::factory()->create();
        $deleted = EmployeeWorkLocation::factory()->create();
        $deleted->delete();

        $this->actingAs($this->admin(), 'sanctum')
            ->postJson('/api/v1/employees', $this->employeePayload([
                'work_location_ids' => [$location->id, $location->id],
            ]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['work_location_ids.0', 'work_location_ids.1']);

        $this->actingAs($this->admin(), 'sanctum')
            ->postJson('/api/v1/employees', $this->employeePayload([
                'work_location_ids' => [$deleted->id],
            ]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['work_location_ids.0']);

        $this->assertDatabaseCount('employees', 0);
    }

    public function test_update_replaces_assignments_and_keeps_history_inactive(): void
    {
        $employee = Employee::factory()->create();
        $old = EmployeeWorkLocation::factory()->create();
        $new = EmployeeWorkLocation::factory()->create();
        $employee->workLocations()->attach($old->id, ['status' => 'active']);

        $this->actingAs($this->admin(), 'sanctum')
            ->putJson("/api/v1/employees/{$employee->id}", ['work_location_ids' => [$new->id]])
            ->assertOk()
            ->assertJsonCount(1, 'data.work_locations')
            ->assertJsonPath('data.work_locations.0.id', $new->id);

        $this->assertSame('inactive', $this->assignmentStatus($employee, $old));
        $this->assertSame('active', $this->assignmentStatus($employee, $new));

        // Re-assigning a previous location reactivates the same row.
        $this->actingAs($this->admin(), 'sanctum')
            ->putJson("/api/v1/employees/{$employee->id}", ['work_location_ids' => [$old->id]])
            ->assertOk();

        $this->assertSame('active', $this->assignmentStatus($employee, $old));
        $this->assertSame('inactive', $this->assignmentStatus($employee, $new));
        $this->assertDatabaseCount('employee_work_location_assignments', 2);
    }

    public function test_nik_cannot_be_changed_after_work_location_assignment_history_exists(): void
    {
        $employee = Employee::factory()->create();
        $location = EmployeeWorkLocation::factory()->create();
        $employee->workLocations()->attach($location->id, ['status' => 'active']);

        $this->actingAs($this->admin(), 'sanctum')
            ->putJson("/api/v1/employees/{$employee->id}", ['nik' => '9876543210987654'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('nik');

        $this->assertDatabaseHas('employees', [
            'id' => $employee->id,
            'nik' => $employee->nik,
        ]);
        $this->assertSame('active', $this->assignmentStatus($employee, $location));
    }

    public function test_update_without_work_location_ids_keeps_assignments(): void
    {
        $employee = Employee::factory()->create();
        $location = EmployeeWorkLocation::factory()->create();
        $employee->workLocations()->attach($location->id, ['status' => 'active']);

        $this->actingAs($this->admin(), 'sanctum')
            ->putJson("/api/v1/employees/{$employee->id}", ['full_name' => 'Renamed'])
            ->assertOk()
            ->assertJsonCount(1, 'data.work_locations');

        $this->assertSame('active', $this->assignmentStatus($employee, $location));
    }

    public function test_update_with_empty_list_clears_assignments(): void
    {
        $employee = Employee::factory()->create();
        $location = EmployeeWorkLocation::factory()->create();
        $employee->workLocations()->attach($location->id, ['status' => 'active']);

        $this->actingAs($this->admin(), 'sanctum')
            ->putJson("/api/v1/employees/{$employee->id}", ['work_location_ids' => []])
            ->assertOk()
            ->assertJsonPath('data.work_locations', []);

        $this->assertSame('inactive', $this->assignmentStatus($employee, $location));
    }

    public function test_employee_list_includes_and_filters_by_work_location(): void
    {
        $jakarta = EmployeeWorkLocation::factory()->create(['city' => 'Jakarta']);
        $bandung = EmployeeWorkLocation::factory()->create(['city' => 'Bandung']);

        $a = Employee::factory()->create();
        $b = Employee::factory()->create();
        $c = Employee::factory()->create();
        $a->workLocations()->attach($jakarta->id, ['status' => 'active']);
        $b->workLocations()->attach($bandung->id, ['status' => 'active']);
        $c->workLocations()->attach($jakarta->id, ['status' => 'inactive']);

        $response = $this->actingAs($this->admin(), 'sanctum')
            ->getJson("/api/v1/employees?work_location_id={$jakarta->id}")
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $a->id)
            ->assertJsonPath('data.0.work_locations.0.city', 'Jakarta');

        $this->assertArrayHasKey('area_type_label', $response->json('data.0.work_locations.0'));
    }

    public function test_work_location_list_shows_active_employee_count(): void
    {
        $location = EmployeeWorkLocation::factory()->create();
        $active = Employee::factory()->count(2)->create();
        $inactive = Employee::factory()->create();

        foreach ($active as $employee) {
            $employee->workLocations()->attach($location->id, ['status' => 'active']);
        }
        $inactive->workLocations()->attach($location->id, ['status' => 'inactive']);

        $this->actingAs($this->admin(), 'sanctum')
            ->getJson('/api/v1/employee-work-locations')
            ->assertOk()
            ->assertJsonPath('data.0.employee_count', 2);
    }

    public function test_deleting_work_location_deactivates_assignments(): void
    {
        $employee = Employee::factory()->create();
        $location = EmployeeWorkLocation::factory()->create();
        $employee->workLocations()->attach($location->id, ['status' => 'active']);

        $this->actingAs($this->admin(), 'sanctum')
            ->deleteJson("/api/v1/employee-work-locations/{$location->id}")
            ->assertOk();

        $this->assertDatabaseHas('employee_work_location_assignments', [
            'employee_nik' => $employee->nik,
            'employee_work_location_id' => $location->id,
            'status' => 'inactive',
        ]);

        $this->actingAs($this->admin(), 'sanctum')
            ->getJson("/api/v1/employees/{$employee->id}")
            ->assertOk()
            ->assertJsonPath('data.work_locations', []);
    }

    public function test_assigning_work_location_requires_employee_update_permission(): void
    {
        $employee = Employee::factory()->create();
        $location = EmployeeWorkLocation::factory()->create();
        $user = User::factory()->create();
        $user->givePermissionTo(['dashboard.view', 'employees.view']);

        $this->actingAs($user, 'sanctum')
            ->putJson("/api/v1/employees/{$employee->id}", ['work_location_ids' => [$location->id]])
            ->assertForbidden();

        $this->assertDatabaseCount('employee_work_location_assignments', 0);
    }
}
