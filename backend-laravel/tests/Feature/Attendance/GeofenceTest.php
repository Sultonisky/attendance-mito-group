<?php

namespace Tests\Feature\Attendance;

use App\Models\AttendanceEvent;
use App\Models\Employee;
use App\Models\EmployeeWorkLocation;
use App\Models\Policy;
use App\Models\PolicyAssignment;
use App\Models\ScheduleAssignment;
use App\Models\Shift;
use App\Models\User;
use App\Models\WorkSchedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Employee check-in/out geofence uses the employee's assigned work locations
 * (same concept as outsource cabang/pin allowlist).
 */
class GeofenceTest extends TestCase
{
    use RefreshDatabase;

    private function makeEmployee(array $overrides = []): Employee
    {
        return Employee::factory()->create($overrides);
    }

    private function assign(Employee $employee, EmployeeWorkLocation $location, string $status = 'active'): EmployeeWorkLocation
    {
        $employee->workLocations()->attach($location->id, ['status' => $status]);

        return $location;
    }

    private function location(float $lat = -6.2, float $lng = 106.8, float $radius = 1000): EmployeeWorkLocation
    {
        return EmployeeWorkLocation::factory()->atCoordinates($lat, $lng, $radius)->create();
    }

    private function userForEmployee(Employee $employee): User
    {
        return User::firstOrCreate(
            ['email' => $employee->email],
            [
                'name' => $employee->full_name,
                'password' => Hash::make('password'),
            ],
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

    private function attend(Employee $employee, string $action, array $payload = []): TestResponse
    {
        return $this->actingAs($this->userForEmployee($employee), 'sanctum')
            ->postJson("/api/v1/attendance/{$action}", array_merge([
                'latitude' => -6.2001,
                'longitude' => 106.8001,
                'accuracy_meters' => 12.5,
            ], $payload));
    }

    public function test_single_assigned_location_is_auto_selected_and_stored_on_event(): void
    {
        $employee = $this->makeEmployee();
        $location = $this->assign($employee, $this->location());
        $this->makeScheduleAndPolicy($employee);

        $this->attend($employee, 'check-in')
            ->assertStatus(201)
            ->assertJsonPath('data.geofence.passed', true)
            ->assertJsonPath('data.geofence.method', 'employee_work_location')
            ->assertJsonPath('data.geofence.employee_work_location_id', $location->id);

        $this->assertDatabaseHas('attendance_events', [
            'employee_id' => $employee->id,
            'employee_work_location_id' => $location->id,
            'accuracy_meters' => 12.5,
        ]);
    }

    public function test_check_in_outside_geofence_blocked(): void
    {
        $employee = $this->makeEmployee();
        $this->assign($employee, $this->location(radius: 100));
        $this->makeScheduleAndPolicy($employee);

        $this->attend($employee, 'check-in', ['latitude' => -6.3, 'longitude' => 106.9])
            ->assertStatus(422)
            ->assertJsonPath('data.error', 'Employee is outside the approved work location geofence.');

        $this->assertSame(0, AttendanceEvent::where('employee_id', $employee->id)->count());
    }

    public function test_check_in_without_assignment_blocked(): void
    {
        $employee = $this->makeEmployee();
        $this->makeScheduleAndPolicy($employee);

        $this->attend($employee, 'check-in')
            ->assertStatus(422)
            ->assertJsonPath('data.error', 'Employee has no active work location assignment.');
    }

    public function test_inactive_assignment_or_location_is_not_usable(): void
    {
        $employee = $this->makeEmployee();
        $this->assign($employee, $this->location(), 'inactive');
        $this->makeScheduleAndPolicy($employee);

        $this->attend($employee, 'check-in')
            ->assertStatus(422)
            ->assertJsonPath('data.error', 'Employee has no active work location assignment.');

        $inactiveLocation = EmployeeWorkLocation::factory()->atCoordinates(-6.2, 106.8, 1000)->inactive()->create();
        $this->assign($employee, $inactiveLocation);

        $this->attend($employee, 'check-in')
            ->assertStatus(422)
            ->assertJsonPath('data.error', 'Assigned work location is inactive or has no coordinates configured.');
    }

    public function test_assigned_location_without_coordinates_blocked(): void
    {
        $employee = $this->makeEmployee();
        $this->assign($employee, EmployeeWorkLocation::factory()->create(['latitude' => null, 'longitude' => null]));
        $this->makeScheduleAndPolicy($employee);

        $this->attend($employee, 'check-in')
            ->assertStatus(422)
            ->assertJsonPath('data.error', 'Assigned work location is inactive or has no coordinates configured.');
    }

    public function test_multiple_locations_require_selection(): void
    {
        $employee = $this->makeEmployee();
        $this->assign($employee, $this->location(-6.5, 107.0));
        $jakarta = $this->assign($employee, $this->location());
        $this->makeScheduleAndPolicy($employee);

        $this->attend($employee, 'check-in')
            ->assertStatus(422)
            ->assertJsonPath('data.error', 'Work location is required for attendance.');

        $this->attend($employee, 'check-in', ['work_location_id' => $jakarta->id])
            ->assertStatus(201)
            ->assertJsonPath('data.geofence.employee_work_location_id', $jakarta->id);
    }

    public function test_unassigned_location_rejected(): void
    {
        $employee = $this->makeEmployee();
        $this->assign($employee, $this->location());
        $other = $this->location();
        $this->makeScheduleAndPolicy($employee);

        $this->attend($employee, 'check-in', ['work_location_id' => $other->id])
            ->assertStatus(422)
            ->assertJsonPath('data.error', 'Work location is not assigned to this employee.');
    }

    public function test_check_out_uses_geofence_and_allows_other_assigned_location(): void
    {
        $employee = $this->makeEmployee();
        $jakarta = $this->assign($employee, $this->location());
        $bandung = $this->assign($employee, $this->location(-6.9, 107.6));
        $this->makeScheduleAndPolicy($employee);

        $this->attend($employee, 'check-in', ['work_location_id' => $jakarta->id])->assertStatus(201);

        $this->attend($employee, 'check-out', ['work_location_id' => $bandung->id])
            ->assertStatus(422)
            ->assertJsonPath('data.error', 'Employee is outside the approved work location geofence.');

        $this->attend($employee, 'check-out', [
            'work_location_id' => $bandung->id,
            'latitude' => -6.9001,
            'longitude' => 107.6001,
        ])->assertOk();

        $this->assertDatabaseHas('attendance_events', [
            'employee_id' => $employee->id,
            'event_type' => 'check_out',
            'employee_work_location_id' => $bandung->id,
        ]);
    }

    public function test_poor_gps_accuracy_blocked(): void
    {
        config(['attendance.gps_max_accuracy_meters' => 100]);

        $employee = $this->makeEmployee();
        $this->assign($employee, $this->location());
        $this->makeScheduleAndPolicy($employee);

        $this->attend($employee, 'check-in', ['accuracy_meters' => 500])
            ->assertStatus(422)
            ->assertJsonPath('data.error', 'GPS accuracy is insufficient for attendance.');
    }

    public function test_check_in_with_invalid_latitude_blocked(): void
    {
        $employee = $this->makeEmployee();
        $this->assign($employee, $this->location());
        $this->makeScheduleAndPolicy($employee);

        $this->attend($employee, 'check-in', ['latitude' => 100, 'longitude' => 106.8])
            ->assertStatus(422);
    }

    public function test_work_locations_endpoint_lists_usable_assigned_locations(): void
    {
        $employee = $this->makeEmployee();
        $usable = $this->assign($employee, $this->location());
        $this->assign($employee, EmployeeWorkLocation::factory()->create(['latitude' => null, 'longitude' => null]));
        $this->assign($employee, $this->location(), 'inactive');
        $this->location();

        $this->actingAs($this->userForEmployee($employee), 'sanctum')
            ->getJson('/api/v1/attendance/work-locations')
            ->assertOk()
            ->assertJsonPath('meta.has_assignment', true)
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $usable->id)
            ->assertJsonPath('data.0.radius_meters', 1000);
    }

    public function test_work_locations_endpoint_reports_missing_assignment(): void
    {
        $employee = $this->makeEmployee();

        $this->actingAs($this->userForEmployee($employee), 'sanctum')
            ->getJson('/api/v1/attendance/work-locations')
            ->assertOk()
            ->assertJsonPath('meta.has_assignment', false)
            ->assertJsonCount(0, 'data');
    }
}
