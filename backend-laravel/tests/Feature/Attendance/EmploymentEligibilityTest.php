<?php

namespace Tests\Feature\Attendance;

use App\Enums\EmploymentStatus;
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

class EmploymentEligibilityTest extends TestCase
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

    // --- Characterization tests (document current legacy behavior) ---

    public function test_permanent_employee_can_check_in(): void
    {
        $employee = $this->makeEmployee(['employment_status' => EmploymentStatus::Permanent->value]);
        $this->makeWorkLocation();
        $this->makeScheduleAndPolicy($employee);

        $response = $this->postCheckIn($employee);

        $response->assertStatus(201);
    }

    public function test_contract_employee_can_check_in(): void
    {
        $employee = $this->makeEmployee(['employment_status' => EmploymentStatus::Contract->value]);
        $this->makeWorkLocation();
        $this->makeScheduleAndPolicy($employee);

        $response = $this->postCheckIn($employee);

        $response->assertStatus(201);
    }

    public function test_resigned_employee_is_allowed_when_end_date_not_past(): void
    {
        $employee = $this->makeEmployee(['employment_status' => EmploymentStatus::Permanent->value]);
        $employee->update(['employment_status' => 'resigned']);
        $this->makeWorkLocation();
        $this->makeScheduleAndPolicy($employee);

        $response = $this->postCheckIn($employee);

        $response->assertStatus(201);
    }

    public function test_terminated_employee_is_allowed_when_end_date_not_past(): void
    {
        $employee = $this->makeEmployee(['employment_status' => 'terminated']);
        $this->makeWorkLocation();
        $this->makeScheduleAndPolicy($employee);

        $response = $this->postCheckIn($employee);

        $response->assertStatus(201);
    }

    // --- Target contract tests (Phase 2B behavior; may fail against current legacy engine) ---

    /**
     * Phase 2B D-01: probation employees are treated as contract and allowed to attend.
     *
     * NOTE: This test is expected to FAIL against the current legacy engine because
     * the legacy engine blocks `employment_status !== 'permanent' && !== 'contract'`.
     * It should become green after Phase 2C.2 aligns the domain engine.
     */
    public function test_probation_employee_is_allowed_when_end_date_not_past(): void
    {
        $employee = $this->makeEmployee([
            'employment_status' => EmploymentStatus::Probation->value,
            'end_date' => null,
        ]);
        $this->makeWorkLocation();
        $this->makeScheduleAndPolicy($employee);

        $response = $this->postCheckIn($employee);

        $response->assertStatus(201);
    }

    /**
     * Phase 2B D-01: outsource employees are allowed to attend.
     *
     * NOTE: This test is expected to FAIL against the current legacy engine because
     * the legacy engine blocks `employment_status !== 'permanent' && !== 'contract'`.
     * It should become green after Phase 2C.2 aligns the domain engine.
     */
    public function test_outsource_employee_is_allowed_when_end_date_not_past(): void
    {
        $employee = $this->makeEmployee([
            'employment_status' => EmploymentStatus::Outsource->value,
            'end_date' => null,
        ]);
        $this->makeWorkLocation();
        $this->makeScheduleAndPolicy($employee);

        $response = $this->postCheckIn($employee);

        $response->assertStatus(201);
    }

    /**
     * Phase 2B D-01: any employee with a past end_date is blocked, regardless of employment_status.
     *
     * NOTE: This test is expected to FAIL against the current legacy engine because
     * the legacy engine ignores end_date. It should become green after Phase 2C.2.
     */
    public function test_employee_with_past_end_date_is_blocked_regardless_of_status(): void
    {
        $pastEndDate = CarbonImmutable::create(2026, 1, 1)->toDateString();

        $employee = $this->makeEmployee([
            'employment_status' => EmploymentStatus::Permanent->value,
            'end_date' => $pastEndDate,
        ]);
        $this->makeWorkLocation();
        $this->makeScheduleAndPolicy($employee);

        $response = $this->postCheckIn($employee);

        $response->assertStatus(422);
    }

    /**
     * Phase 2B D-01: employee with future end_date remains eligible.
     */
    public function test_employee_with_future_end_date_remains_eligible(): void
    {
        $futureEndDate = CarbonImmutable::create(2099, 12, 31)->toDateString();

        $employee = $this->makeEmployee([
            'employment_status' => EmploymentStatus::Contract->value,
            'end_date' => $futureEndDate,
        ]);
        $this->makeWorkLocation();
        $this->makeScheduleAndPolicy($employee);

        $response = $this->postCheckIn($employee);

        $response->assertStatus(201);
    }
}
