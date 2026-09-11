<?php

namespace Tests\Feature\Attendance;

use App\Enums\EmploymentStatus;
use App\Models\Employee;
use App\Models\User;
use App\Models\WorkLocation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CheckInTest extends TestCase
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

    public function test_authenticated_user_can_check_in(): void
    {
        [$user] = $this->makeActiveUserAndEmployee();
        $this->makeWorkLocation();

        $response = $this->actingAs($user, 'sanctum')->postJson('/api/v1/attendance/check-in', [
            'latitude' => -6.2001,
            'longitude' => 106.8001,
            'accuracy' => 12.5,
        ]);

        $response->assertStatus(200);
        $response->assertJson([
            'success' => true,
            'message' => 'Check-in recorded successfully.',
        ]);
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

    public function test_unauthenticated_user_receives_401(): void
    {
        $response = $this->postJson('/api/v1/attendance/check-in', [
            'latitude' => -6.2001,
            'longitude' => 106.8001,
        ]);

        $response->assertStatus(401);
    }

    public function test_check_in_outside_geofence_throws_exception(): void
    {
        [$user] = $this->makeActiveUserAndEmployee();
        $this->makeWorkLocation();

        $response = $this->actingAs($user, 'sanctum')->postJson('/api/v1/attendance/check-in', [
            'latitude' => -6.3,
            'longitude' => 106.9,
            'accuracy' => 12.5,
        ]);

        $response->assertStatus(422);
    }

    public function test_check_in_with_invalid_latitude_throws_exception(): void
    {
        [$user] = $this->makeActiveUserAndEmployee();

        $response = $this->actingAs($user, 'sanctum')->postJson('/api/v1/attendance/check-in', [
            'latitude' => 100,
            'longitude' => 106.8,
            'accuracy' => 12.5,
        ]);

        $response->assertStatus(422);
    }

    public function test_duplicate_check_in_throws_exception(): void
    {
        [$user, $employee] = $this->makeActiveUserAndEmployee();
        $this->makeWorkLocation();

        $first = $this->actingAs($user, 'sanctum')->postJson('/api/v1/attendance/check-in', [
            'latitude' => -6.2001,
            'longitude' => 106.8001,
            'accuracy' => 12.5,
        ]);
        $first->assertStatus(200);

        $response = $this->actingAs($user, 'sanctum')->postJson('/api/v1/attendance/check-in', [
            'latitude' => -6.2001,
            'longitude' => 106.8001,
            'accuracy' => 12.5,
        ]);

        $response->assertStatus(409);
    }

    public function test_inactive_employee_cannot_check_in(): void
    {
        $user = $this->makeUser();
        $employee = $this->makeEmployee(['end_date' => now()->subDay()]);
        $employee->update(['user_id' => $user->id]);

        $response = $this->actingAs($user, 'sanctum')->postJson('/api/v1/attendance/check-in', [
            'latitude' => -6.2001,
            'longitude' => 106.8001,
            'accuracy' => 12.5,
        ]);

        $response->assertStatus(422);
    }
}
