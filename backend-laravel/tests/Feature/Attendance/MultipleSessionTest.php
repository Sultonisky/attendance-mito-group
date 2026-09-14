<?php

namespace Tests\Feature\Attendance;

use App\Enums\EmploymentStatus;
use App\Models\Employee;
use App\Models\User;
use App\Models\WorkLocation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MultipleSessionTest extends TestCase
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

    public function test_legitimate_second_check_in_after_checkout_is_allowed(): void
    {
        [$user, $employee] = $this->makeActiveUserAndEmployee();
        $this->makeWorkLocation();

        $firstCheckIn = $this->actingAs($user, 'sanctum')->postJson('/api/v1/attendance/check-in', [
            'latitude' => -6.2001,
            'longitude' => 106.8001,
            'accuracy' => 12.5,
        ]);
        $firstCheckIn->assertStatus(200);

        $firstCheckOut = $this->actingAs($user, 'sanctum')->postJson('/api/v1/attendance/check-out', [
            'latitude' => -6.2001,
            'longitude' => 106.8001,
            'accuracy' => 12.5,
        ]);
        $firstCheckOut->assertStatus(200);

        $secondCheckIn = $this->actingAs($user, 'sanctum')->postJson('/api/v1/attendance/check-in', [
            'latitude' => -6.2001,
            'longitude' => 106.8001,
            'accuracy' => 12.5,
        ]);

        $secondCheckIn->assertStatus(200);
        $secondCheckIn->assertJson([
            'success' => true,
            'message' => 'Check-in recorded successfully.',
        ]);

        $record = \App\Models\AttendanceRecord::where('employee_id', $employee->id)
            ->orderByDesc('created_at')
            ->first();

        $this->assertSame(2, $record->sessions()->count());
        $this->assertSame(1, $record->sessions()->where('status', 'open')->count());
    }
}
