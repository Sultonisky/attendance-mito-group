<?php

namespace Tests\Feature\Attendance;

use App\Enums\AttendanceStatus;
use App\Enums\EmploymentStatus;
use App\Models\AttendanceRecord;
use App\Models\Employee;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AttendanceIndexFilterTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    /**
     * @return array{0: User, 1: Employee}
     */
    private function makeActiveUserAndEmployee(): array
    {
        $user = User::factory()->create();
        $user->assignRole('USER');

        $employee = Employee::factory()->create([
            'employment_status' => EmploymentStatus::Permanent->value,
            'user_id' => $user->id,
        ]);

        return [$user, $employee];
    }

    public function test_employee_can_list_own_attendance_with_date_and_status_filters(): void
    {
        [$user, $employee] = $this->makeActiveUserAndEmployee();
        $other = Employee::factory()->create([
            'employment_status' => EmploymentStatus::Permanent->value,
        ]);

        AttendanceRecord::factory()->forEmployee($employee)->onDate('2026-09-01')->status(AttendanceStatus::Present->value)->create();
        AttendanceRecord::factory()->forEmployee($employee)->onDate('2026-09-10')->status(AttendanceStatus::Late->value)->create();
        AttendanceRecord::factory()->forEmployee($employee)->onDate('2026-09-20')->status(AttendanceStatus::Absent->value)->create();
        AttendanceRecord::factory()->forEmployee($other)->onDate('2026-09-10')->status(AttendanceStatus::Present->value)->create();

        $response = $this->actingAs($user, 'sanctum')->getJson(
            '/api/v1/attendance?from=2026-09-05&to=2026-09-15&status=late&per_page=10'
        );

        $response->assertOk();
        $response->assertJsonPath('meta.total', 1);
        $response->assertJsonPath('data.0.data.attendance_date', '2026-09-10');
        $response->assertJsonPath('data.0.data.status', AttendanceStatus::Late->value);
        $response->assertJsonPath('data.0.data.employee_id', $employee->id);
    }

    public function test_employee_index_respects_per_page(): void
    {
        [$user, $employee] = $this->makeActiveUserAndEmployee();

        foreach (range(1, 5) as $day) {
            AttendanceRecord::factory()
                ->forEmployee($employee)
                ->onDate(sprintf('2026-09-%02d', $day))
                ->status(AttendanceStatus::Present->value)
                ->create();
        }

        $response = $this->actingAs($user, 'sanctum')->getJson('/api/v1/attendance?per_page=2');

        $response->assertOk();
        $response->assertJsonPath('meta.per_page', 2);
        $response->assertJsonPath('meta.total', 5);
        $this->assertCount(2, $response->json('data'));
    }

    public function test_index_validation_rejects_invalid_status(): void
    {
        [$user] = $this->makeActiveUserAndEmployee();

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/attendance?status=not_a_status')
            ->assertStatus(422);
    }
}
