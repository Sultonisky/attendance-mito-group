<?php

namespace Tests\Feature\Attendance;

use App\Models\AttendanceEvent;
use App\Models\AttendanceRecord;
use App\Models\AttendanceSession;
use App\Models\AttendanceVerification;
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

class TransactionRollbackExpandedTest extends TestCase
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

    private function postCheckIn(Employee $employee, array $payload = []): TestResponse
    {
        return $this->actingAs($this->userForEmployee($employee), 'sanctum')
            ->postJson('/api/v1/attendance/check-in', array_merge([
                'latitude' => -6.2001,
                'longitude' => 106.8001,
                'accuracy' => 12.5,
            ], $payload));
    }

    public function test_check_in_rolls_back_on_geofence_failure(): void
    {
        $employee = $this->makeEmployee();
        $workLocation = $this->makeWorkLocation();
        $this->makeScheduleAndPolicy($employee);

        $initialCount = AttendanceRecord::where('employee_id', $employee->id)->count();

        $response = $this->postCheckIn($employee, [
            'work_location_id' => $workLocation->id,
            'latitude' => -6.3,
            'longitude' => 106.9,
            'accuracy' => 12.5,
        ]);

        $response->assertStatus(422);

        $this->assertSame($initialCount, AttendanceRecord::where('employee_id', $employee->id)->count());
        $this->assertSame(0, AttendanceSession::whereHas('attendanceRecord', function ($query) use ($employee) {
            $query->where('employee_id', $employee->id);
        })->count());
        $this->assertSame(0, AttendanceEvent::where('employee_id', $employee->id)->count());
        $this->assertSame(0, AttendanceVerification::where('employee_id', $employee->id)->count());
    }

    public function test_check_in_rolls_back_on_schedule_failure(): void
    {
        $employee = $this->makeEmployee();
        $this->makeWorkLocation();
        // No schedule created

        $initialCount = AttendanceRecord::where('employee_id', $employee->id)->count();

        $response = $this->postCheckIn($employee, [
            'latitude' => -6.2001,
            'longitude' => 106.8001,
            'accuracy' => 12.5,
        ]);

        $response->assertStatus(422);

        $this->assertSame($initialCount, AttendanceRecord::where('employee_id', $employee->id)->count());
        $this->assertSame(0, AttendanceSession::whereHas('attendanceRecord', function ($query) use ($employee) {
            $query->where('employee_id', $employee->id);
        })->count());
        $this->assertSame(0, AttendanceEvent::where('employee_id', $employee->id)->count());
    }

    public function test_successful_check_in_commits_all_records(): void
    {
        $employee = $this->makeEmployee();
        $this->makeWorkLocation();
        $this->makeScheduleAndPolicy($employee);

        $response = $this->postCheckIn($employee, [
            'latitude' => -6.2001,
            'longitude' => 106.8001,
            'accuracy' => 12.5,
        ]);

        $response->assertStatus(201);

        $this->assertSame(1, AttendanceRecord::where('employee_id', $employee->id)->count());
        $this->assertSame(1, AttendanceSession::whereHas('attendanceRecord', function ($query) use ($employee) {
            $query->where('employee_id', $employee->id);
        })->count());
        $this->assertSame(1, AttendanceEvent::where('employee_id', $employee->id)->count());
    }
}
