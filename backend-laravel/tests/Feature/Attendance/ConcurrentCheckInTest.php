<?php

namespace Tests\Feature\Attendance;

use App\Enums\EmploymentStatus;
use App\Models\Employee;
use App\Models\User;
use App\Models\WorkLocation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ConcurrentCheckInTest extends TestCase
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

    public function test_database_unique_constraint_prevents_duplicate_attendance_record(): void
    {
        [$user, $employee] = $this->makeActiveUserAndEmployee();
        $this->makeWorkLocation();

        $first = $this->actingAs($user, 'sanctum')->postJson('/api/v1/attendance/check-in', [
            'latitude' => -6.2001,
            'longitude' => 106.8001,
            'accuracy' => 12.5,
        ]);
        $first->assertStatus(200);

        $recordCount = \App\Models\AttendanceRecord::where('employee_id', $employee->id)->count();
        $this->assertSame(1, $recordCount);

        $second = $this->actingAs($user, 'sanctum')->postJson('/api/v1/attendance/check-in', [
            'latitude' => -6.2001,
            'longitude' => 106.8001,
            'accuracy' => 12.5,
        ]);
        $second->assertStatus(409);

        $this->assertSame(1, \App\Models\AttendanceRecord::where('employee_id', $employee->id)->count());
        $this->assertSame(1, \App\Models\AttendanceSession::whereHas('attendanceRecord', function ($query) use ($employee) {
            $query->where('employee_id', $employee->id);
        })->count());
    }
}
