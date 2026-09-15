<?php

namespace Tests\Feature\Attendance;

use App\Enums\EmploymentStatus;
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
use Tests\TestCase;

class CrossMidnightTest extends TestCase
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

    public function test_cross_midnight_check_in_and_checkout_resolves_correct_date(): void
    {
        [$user, $employee] = $this->makeActiveUserAndEmployee();
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
        Policy::factory()->create(['name' => 'Default']);
        PolicyAssignment::factory()->forEmployee($employee)->forPolicy(Policy::first())->create([
            'effective_from' => '2026-01-01',
            'effective_to' => null,
        ]);

        $checkInAt = CarbonImmutable::parse('2026-09-11 22:05:00');
        $checkOutAt = CarbonImmutable::parse('2026-09-12 05:55:00');

        $this->travelTo($checkInAt);
        $checkInResponse = $this->actingAs($user, 'sanctum')->postJson('/api/v1/attendance/check-in', [
            'latitude' => -6.2001,
            'longitude' => 106.8001,
            'accuracy' => 12.5,
        ]);
        $this->travelBack();

        // Phase 8.1 controller returns 201 for check-in success.
        $checkInResponse->assertStatus(201);
        $checkInResponse->assertJson(['success' => true]);

        $record = AttendanceRecord::where('employee_id', $employee->id)
            ->orderByDesc('created_at')
            ->first();

        // Cross-midnight: check-in at 22:05 on D belongs to attendance_date D, not D+1.
        $this->assertSame('2026-09-11', $record->attendance_date->format('Y-m-d'));

        $this->travelTo($checkOutAt);
        $checkOutResponse = $this->actingAs($user, 'sanctum')->postJson('/api/v1/attendance/check-out', [
            'latitude' => -6.2001,
            'longitude' => 106.8001,
            'accuracy' => 12.5,
        ]);
        $this->travelBack();

        // Phase 8.1 controller returns 200 for check-out success.
        $checkOutResponse->assertStatus(200);
        $checkOutResponse->assertJson(['success' => true]);

        $record->refresh();
        // Session belongs to the original check-in date, not the checkout date.
        $this->assertSame('2026-09-11', $record->attendance_date->format('Y-m-d'));
        // Phase 8.1 engine resolves status as 'present' on checkout.
        $this->assertNotNull($record->status);

        $session = $record->sessions()->first();
        $this->assertSame('closed', $session->status);
        $this->assertGreaterThan(400, $session->duration_minutes);
    }
}
