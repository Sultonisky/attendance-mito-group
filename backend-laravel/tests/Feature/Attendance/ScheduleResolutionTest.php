<?php

namespace Tests\Feature\Attendance;

use App\Models\AttendanceRecord;
use App\Models\Employee;
use App\Models\Policy;
use App\Models\PolicyAssignment;
use App\Models\ScheduleAssignment;
use App\Models\Shift;
use App\Models\User;
use App\Models\WorkLocation;
use App\Models\WorkSchedule;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class ScheduleResolutionTest extends TestCase
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

    private function postCheckIn(Employee $employee, array $payload = []): TestResponse
    {
        return $this->actingAs($this->userForEmployee($employee), 'sanctum')
            ->postJson('/api/v1/attendance/check-in', array_merge([
                'latitude' => -6.2001,
                'longitude' => 106.8001,
                'accuracy' => 12.5,
            ], $payload));
    }

    private function postCheckOut(Employee $employee, array $payload = []): TestResponse
    {
        return $this->actingAs($this->userForEmployee($employee), 'sanctum')
            ->postJson('/api/v1/attendance/check-out', array_merge([
                'latitude' => -6.2001,
                'longitude' => 106.8001,
                'accuracy' => 12.5,
            ], $payload));
    }

    public function test_check_in_succeeds_with_valid_schedule(): void
    {
        $employee = $this->makeEmployee();
        $this->makeWorkLocation();

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

        $response = $this->postCheckIn($employee);

        $response->assertStatus(201);
    }

    public function test_check_in_blocks_when_no_active_schedule(): void
    {
        $employee = $this->makeEmployee();
        $this->makeWorkLocation();

        $policy = Policy::factory()->create();
        PolicyAssignment::factory()->forEmployee($employee)->forPolicy($policy)->create([
            'effective_from' => now()->subYear()->toDateString(),
            'effective_to' => null,
        ]);

        $response = $this->postCheckIn($employee);

        $response->assertStatus(422);
        $response->assertJsonPath('data.error', 'No active schedule found for this date.');
    }

    public function test_cross_midnight_shift_check_in_resolves_previous_date(): void
    {
        $employee = $this->makeEmployee();
        $this->makeWorkLocation();

        $schedule = WorkSchedule::factory()->create(['name' => 'Night Shift']);
        Shift::factory()->forSchedule($schedule)->create([
            'name' => 'Night',
            'start_time' => '22:00:00',
            'end_time' => '06:00:00',
            'cross_midnight' => true,
        ]);
        ScheduleAssignment::factory()->forEmployee($employee)->forSchedule($schedule)->create([
            'effective_from' => '2026-01-01',
            'effective_to' => null,
        ]);

        $policy = Policy::factory()->create();
        PolicyAssignment::factory()->forEmployee($employee)->forPolicy($policy)->create([
            'effective_from' => '2026-01-01',
            'effective_to' => null,
        ]);

        $checkInAt = CarbonImmutable::create(2026, 9, 11, 22, 5, 0);

        $this->travelTo($checkInAt);
        $response = $this->postCheckIn($employee);
        $this->travelBack();

        $response->assertStatus(201);

        $record = AttendanceRecord::where('employee_id', $employee->id)
            ->orderByDesc('created_at')
            ->first();

        $this->assertSame('2026-09-11', $record->attendance_date->format('Y-m-d'));
    }

    public function test_cross_midnight_check_out_resolves_correct_session_date(): void
    {
        $employee = $this->makeEmployee();
        $this->makeWorkLocation();

        $schedule = WorkSchedule::factory()->create(['name' => 'Night Shift']);
        Shift::factory()->forSchedule($schedule)->create([
            'name' => 'Night',
            'start_time' => '22:00:00',
            'end_time' => '06:00:00',
            'cross_midnight' => true,
        ]);
        ScheduleAssignment::factory()->forEmployee($employee)->forSchedule($schedule)->create([
            'effective_from' => '2026-01-01',
            'effective_to' => null,
        ]);

        $policy = Policy::factory()->create();
        PolicyAssignment::factory()->forEmployee($employee)->forPolicy($policy)->create([
            'effective_from' => '2026-01-01',
            'effective_to' => null,
        ]);

        $checkInAt = CarbonImmutable::create(2026, 9, 11, 22, 0, 0);
        $checkOutAt = CarbonImmutable::create(2026, 9, 12, 5, 55, 0);

        $this->travelTo($checkInAt);
        $this->postCheckIn($employee)->assertStatus(201);
        $this->travelBack();

        $this->travelTo($checkOutAt);
        $response = $this->postCheckOut($employee);
        $this->travelBack();

        $response->assertStatus(200);

        $record = AttendanceRecord::where('employee_id', $employee->id)->first();
        $this->assertSame('2026-09-11', $record->attendance_date->format('Y-m-d'));
    }
}
