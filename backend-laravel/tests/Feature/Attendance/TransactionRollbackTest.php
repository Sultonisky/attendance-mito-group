<?php

namespace Tests\Feature\Attendance;

use App\Enums\EmploymentStatus;
use App\Models\AttendanceEvent;
use App\Models\AttendanceRecord;
use App\Models\AttendanceSession;
use App\Models\AttendanceVerification;
use App\Models\Employee;
use App\Models\User;
use App\Models\WorkLocation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TransactionRollbackTest extends TestCase
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

    public function test_check_in_rolls_back_on_geofence_failure(): void
    {
        [$user, $employee] = $this->makeActiveUserAndEmployee();
        $this->makeWorkLocation();

        $initialCount = AttendanceRecord::where('employee_id', $employee->id)->count();

        $response = $this->actingAs($user, 'sanctum')->postJson('/api/v1/attendance/check-in', [
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
}
