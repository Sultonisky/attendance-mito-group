<?php

namespace Tests\Feature\Attendance;

use App\Enums\EmploymentStatus;
use App\Models\Employee;
use App\Models\User;
use App\Models\WorkLocation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AttendanceAuthorizationTest extends TestCase
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
        $user->employee()->save($employee);

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

    public function test_unauthenticated_check_in_returns_401(): void
    {
        $response = $this->postJson('/api/v1/attendance/check-in', [
            'latitude' => -6.2001,
            'longitude' => 106.8001,
        ]);

        $response->assertStatus(401);
    }

    public function test_unauthenticated_check_out_returns_401(): void
    {
        $response = $this->postJson('/api/v1/attendance/check-out', [
            'latitude' => -6.2001,
            'longitude' => 106.8001,
        ]);

        $response->assertStatus(401);
    }
}
