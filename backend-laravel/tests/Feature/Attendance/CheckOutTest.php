<?php

namespace Tests\Feature\Attendance;

use App\Enums\EmploymentStatus;
use App\Models\Employee;
use App\Models\User;
use App\Models\WorkLocation;
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

    public function test_authenticated_user_can_check_out_after_check_in(): void
    {
        [$user] = $this->makeActiveUserAndEmployee();
        $this->makeWorkLocation();

        $this->actingAs($user, 'sanctum')->postJson('/api/v1/attendance/check-in', [
            'latitude' => -6.2001,
            'longitude' => 106.8001,
            'accuracy' => 12.5,
        ]);

        $response = $this->actingAs($user, 'sanctum')->postJson('/api/v1/attendance/check-out', [
            'latitude' => -6.2001,
            'longitude' => 106.8001,
            'accuracy' => 12.5,
        ]);

        $response->assertStatus(200);
        $response->assertJson([
            'success' => true,
            'message' => 'Check-out recorded successfully.',
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
        $this->makeWorkLocation();

        $this->actingAs($user, 'sanctum')->postJson('/api/v1/attendance/check-in', [
            'latitude' => -6.2001,
            'longitude' => 106.8001,
            'accuracy' => 12.5,
        ]);

        $response = $this->actingAs($user, 'sanctum')->postJson('/api/v1/attendance/check-out', [
            'latitude' => -6.3,
            'longitude' => 106.9,
            'accuracy' => 12.5,
        ]);

        $response->assertStatus(422);
    }
}
