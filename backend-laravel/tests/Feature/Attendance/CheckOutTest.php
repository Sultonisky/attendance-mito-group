<?php

namespace Tests\Feature\Attendance;

use App\Enums\EmploymentStatus;
use App\Models\Employee;
use App\Models\Policy;
use App\Models\PolicyAssignment;
use App\Models\ScheduleAssignment;
use App\Models\Shift;
use App\Models\User;
use App\Models\WorkLocation;
use App\Models\WorkSchedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CheckOutTest extends TestCase
{
    use RefreshDatabase;

    private function makeUser(array $overrides = []): User
    {
        return User::factory()->create($overrides);
    }

    private function makeEmployee(array $overrides = []): Employee
    {
        return Employee::factory()->create($overrides);
    }

    private function makeActiveUserAndEmployee(): array
    {
        $user = $this->makeUser();
        $employee = $this->makeEmployee(['employment_status' => EmploymentStatus::Permanent->value]);
        $employee->update(['user_id' => $user->id]);

        return [$user, $employee];
    }

    private function makeWorkLocation(): WorkLocation
    {
        return WorkLocation::factory()->create([
            'latitude' => -6.2,
            'longitude' => 106.8,
            'radius_meters' => 1000,
        ]);
    }

    private function makeScheduleAndPolicy(Employee $employee): void
    {
        $schedule = WorkSchedule::factory()->create();
        Shift::factory()->forSchedule($schedule)->create([
            'start_time' => '08:00:00',
            'end_time' => '17:00:00',
        ]);
        ScheduleAssignment::factory()->forEmployee($employee)->forSchedule($schedule)->create([
            'effective_from' => now()->subYear()->toDateString(),
            'effective_to' => null,
        ]);

        $policy = Policy::factory()->create();
        PolicyAssignment::factory()->forEmployee($employee)->forPolicy($policy)->create([
            'effective_from' => now()->subYear()->toDateString(),
            'effective_to' => null,
        ]);
    }

    public function test_authenticated_user_can_check_out_after_check_in(): void
    {
        [$user, $employee] = $this->makeActiveUserAndEmployee();
        $this->makeWorkLocation();
        $this->makeScheduleAndPolicy($employee);

        // Phase 8.1 controller returns 201 for check-in.
        $this->actingAs($user, 'sanctum')->postJson('/api/v1/attendance/check-in', [
            'latitude' => -6.2001,
            'longitude' => 106.8001,
            'accuracy' => 12.5,
        ])->assertStatus(201);

        $response = $this->actingAs($user, 'sanctum')->postJson('/api/v1/attendance/check-out', [
            'latitude' => -6.2001,
            'longitude' => 106.8001,
            'accuracy' => 12.5,
        ]);

        // Phase 8.1 controller returns 200 for check-out success.
        $response->assertStatus(200);
        $response->assertJson(['success' => true]);
        $response->assertJsonStructure([
            'data' => [
                'id',
                'employee_id',
                'attendance_date',
                'status',
                'sessions' => [],
            ],
        ]);
    }

    public function test_check_out_without_open_session_throws_exception(): void
    {
        [$user] = $this->makeActiveUserAndEmployee();
        $this->makeWorkLocation();

        $response = $this->actingAs($user, 'sanctum')->postJson('/api/v1/attendance/check-out', [
            'latitude' => -6.2001,
            'longitude' => 106.8001,
            'accuracy' => 12.5,
        ]);

        $response->assertStatus(422);
    }

    public function test_check_out_outside_geofence_throws_exception(): void
    {
        [$user, $employee] = $this->makeActiveUserAndEmployee();
        $workLocation = $this->makeWorkLocation();
        $this->makeScheduleAndPolicy($employee);

        $this->actingAs($user, 'sanctum')->postJson('/api/v1/attendance/check-in', [
            'latitude' => -6.2001,
            'longitude' => 106.8001,
            'accuracy' => 12.5,
        ])->assertStatus(201);

        $response = $this->actingAs($user, 'sanctum')->postJson('/api/v1/attendance/check-out', [
            'latitude' => -6.3,
            'longitude' => 106.9,
            'accuracy' => 12.5,
            'work_location_id' => $workLocation->id,
        ]);

        $response->assertStatus(422);
    }
}
