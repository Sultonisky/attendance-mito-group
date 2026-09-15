<?php

namespace Tests\Feature\Attendance;

use App\Models\Employee;
use App\Models\Policy;
use App\Models\PolicyAssignment;
use App\Models\ScheduleAssignment;
use App\Models\Shift;
use App\Models\User;
use App\Models\WorkLocation;
use App\Models\WorkSchedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class GeofenceTest extends TestCase
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

    private function userForEmployee(Employee $employee, array $overrides = []): User
    {
        return User::firstOrCreate(
            ['email' => $employee->email],
            array_merge([
                'name' => $employee->full_name,
                'password' => Hash::make('password'),
            ], $overrides)
        );
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

    private function postCheckIn(Employee $employee, array $payload = []): TestResponse
    {
        return $this->actingAs($this->userForEmployee($employee), 'sanctum')
            ->postJson('/api/v1/attendance/check-in', array_merge([
                'latitude' => -6.2001,
                'longitude' => 106.8001,
                'accuracy' => 12.5,
            ], $payload));
    }

    public function test_check_in_inside_geofence_allowed(): void
    {
        $employee = $this->makeEmployee();
        $workLocation = WorkLocation::factory()->atCoordinates(-6.2, 106.8, 1000)->create();
        $this->makeScheduleAndPolicy($employee);

        $response = $this->postCheckIn($employee, [
            'work_location_id' => $workLocation->id,
            'latitude' => -6.2001,
            'longitude' => 106.8001,
        ]);

        $response->assertStatus(201);
        $response->assertJsonPath('data.geofence.passed', true);
    }

    public function test_check_in_outside_geofence_blocked(): void
    {
        $employee = $this->makeEmployee();
        $workLocation = WorkLocation::factory()->atCoordinates(-6.2, 106.8, 100)->create();
        $this->makeScheduleAndPolicy($employee);

        $response = $this->postCheckIn($employee, [
            'work_location_id' => $workLocation->id,
            'latitude' => -6.3,
            'longitude' => 106.9,
        ]);

        $response->assertStatus(422);
    }

    public function test_check_in_returns_distance_meters_when_coordinates_provided(): void
    {
        $employee = $this->makeEmployee();
        $workLocation = WorkLocation::factory()->atCoordinates(-6.2, 106.8, 10000)->create();
        $this->makeScheduleAndPolicy($employee);

        $response = $this->postCheckIn($employee, [
            'work_location_id' => $workLocation->id,
            'latitude' => -6.2001,
            'longitude' => 106.8001,
        ]);

        $response->assertStatus(201);
        $response->assertJsonPath('data.geofence.passed', true);
        $response->assertJsonPath('data.geofence.distance_meters', function ($value) {
            $this->assertNotNull($value);
            $this->assertIsNumeric($value);
            $this->assertGreaterThanOrEqual(0, $value);

            return true;
        });
    }

    public function test_check_in_outside_geofence_returns_distance_meters(): void
    {
        $employee = $this->makeEmployee();
        $workLocation = WorkLocation::factory()->atCoordinates(-6.2, 106.8, 100)->create();
        $this->makeScheduleAndPolicy($employee);

        $response = $this->postCheckIn($employee, [
            'work_location_id' => $workLocation->id,
            'latitude' => -6.3,
            'longitude' => 106.9,
        ]);

        $response->assertStatus(422);
        $response->assertJsonPath('data.geofence.passed', false);
        $response->assertJsonPath('data.geofence.distance_meters', function ($value) {
            $this->assertNotNull($value);
            $this->assertIsNumeric($value);
            $this->assertGreaterThan(100, $value);

            return true;
        });
    }

    public function test_check_in_without_work_location_skips_geofence(): void
    {
        $employee = $this->makeEmployee();
        $this->makeScheduleAndPolicy($employee);

        $response = $this->postCheckIn($employee, [
            'latitude' => -6.2001,
            'longitude' => 106.8001,
        ]);

        $response->assertStatus(201);
        $response->assertJsonPath('data.geofence.passed', true);
        $response->assertJsonPath('data.geofence.method', 'skipped');
    }

    public function test_check_in_with_invalid_latitude_blocked(): void
    {
        $employee = $this->makeEmployee();
        $this->makeScheduleAndPolicy($employee);

        $response = $this->postCheckIn($employee, [
            'latitude' => 100,
            'longitude' => 106.8,
        ]);

        $response->assertStatus(422);
    }
}
