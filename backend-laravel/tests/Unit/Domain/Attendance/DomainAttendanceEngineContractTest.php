<?php

namespace Tests\Unit\Domain\Attendance;

use App\Domain\Attendance\DTOs\AttendanceOperationData;
use App\Domain\Attendance\Engines\AttendanceEngine;
use App\Domain\Attendance\Exceptions\AttendanceAlreadyCheckedInException;
use App\Domain\Attendance\Rules\AttendanceStateRule;
use App\Domain\Attendance\Rules\EarlyCheckoutRule;
use App\Domain\Attendance\Rules\GeofenceRule;
use App\Domain\Attendance\Rules\GpsValidationRule;
use App\Domain\Attendance\Rules\LateDetectionRule;
use App\Domain\Policy\Engines\PolicyEngine;
use App\Domain\Schedule\Engines\ScheduleEngine;
use App\Enums\AttendanceEventType;
use App\Enums\EmploymentStatus;
use App\Exceptions\Domain\InactiveEmployeeException;
use App\Models\AttendanceVerification;
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
use Tests\TestCase;

class DomainAttendanceEngineContractTest extends TestCase
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

    private function makeEngine(): AttendanceEngine
    {
        return new AttendanceEngine(
            new PolicyEngine,
            new ScheduleEngine,
            new GpsValidationRule,
            new GeofenceRule,
            new LateDetectionRule,
            new EarlyCheckoutRule,
            new AttendanceStateRule,
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

    private function makeSchedule(Employee $employee): WorkSchedule
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

        return $schedule;
    }

    private function makePolicy(Employee $employee): Policy
    {
        $policy = Policy::factory()->create();
        PolicyAssignment::factory()->forEmployee($employee)->forPolicy($policy)->create([
            'effective_from' => now()->subYear()->toDateString(),
            'effective_to' => null,
        ]);

        return $policy;
    }

    private function makeOperationData(Employee $employee, CarbonImmutable $at, ?int $workLocationId = null): AttendanceOperationData
    {
        return new AttendanceOperationData(
            employeeId: $employee->id,
            latitude: -6.2001,
            longitude: 106.8001,
            accuracy: 12.5,
            deviceIdentifier: 'test-device',
            source: 'mobile',
            workLocationId: $workLocationId ?? $this->makeWorkLocation()->id,
            occurredAt: $at,
            eventType: AttendanceEventType::CheckIn,
        );
    }

    // --- Employment eligibility ---

    public function test_domain_engine_throws_for_ended_employee(): void
    {
        $employee = $this->makeEmployee([
            'employment_status' => EmploymentStatus::Permanent->value,
            'end_date' => now()->subDay()->toDateString(),
        ]);
        $this->makeSchedule($employee);
        $this->makePolicy($employee);
        $engine = $this->makeEngine();

        $this->expectException(InactiveEmployeeException::class);

        $engine->checkIn($employee, $this->makeOperationData($employee, CarbonImmutable::now()));
    }

    public function test_domain_engine_allows_probation_employee_with_future_end_date(): void
    {
        $employee = $this->makeEmployee([
            'employment_status' => EmploymentStatus::Probation->value,
            'end_date' => now()->addYear()->toDateString(),
        ]);
        $this->makeSchedule($employee);
        $this->makePolicy($employee);
        $engine = $this->makeEngine();

        $result = $engine->checkIn($employee, $this->makeOperationData($employee, CarbonImmutable::now()));

        $this->assertNotNull($result->attendanceRecord);
        $this->assertSame('incomplete', $result->attendanceRecord->status);
    }

    public function test_domain_engine_allows_outsource_employee_with_future_end_date(): void
    {
        $employee = $this->makeEmployee([
            'employment_status' => EmploymentStatus::Outsource->value,
            'end_date' => now()->addYear()->toDateString(),
        ]);
        $this->makeSchedule($employee);
        $this->makePolicy($employee);
        $engine = $this->makeEngine();

        $result = $engine->checkIn($employee, $this->makeOperationData($employee, CarbonImmutable::now()));

        $this->assertNotNull($result->attendanceRecord);
    }

    // --- Domain engine does NOT create AttendanceVerification ---

    public function test_domain_engine_does_not_create_verification(): void
    {
        $employee = $this->makeEmployee([
            'employment_status' => EmploymentStatus::Permanent->value,
            'end_date' => null,
        ]);
        $this->makeSchedule($employee);
        $this->makePolicy($employee);
        $engine = $this->makeEngine();

        $result = $engine->checkIn($employee, $this->makeOperationData($employee, CarbonImmutable::now()));

        $this->assertNull($result->verification);
    }

    public function test_domain_engine_does_not_create_face_verification(): void
    {
        $employee = $this->makeEmployee([
            'employment_status' => EmploymentStatus::Permanent->value,
            'end_date' => null,
        ]);
        $this->makeSchedule($employee);
        $this->makePolicy($employee);
        $engine = $this->makeEngine();

        $result = $engine->checkIn($employee, $this->makeOperationData($employee, CarbonImmutable::now()));

        $this->assertNull($result->verification);
        $this->assertDatabaseMissing('attendance_verifications', [
            'employee_id' => $employee->id,
            'verification_type' => 'face',
        ]);
    }

    public function test_domain_engine_does_not_create_audit_log(): void
    {
        $employee = $this->makeEmployee([
            'employment_status' => EmploymentStatus::Permanent->value,
            'end_date' => null,
        ]);
        $this->makeSchedule($employee);
        $this->makePolicy($employee);
        $engine = $this->makeEngine();

        $engine->checkIn($employee, $this->makeOperationData($employee, CarbonImmutable::now()));

        $this->assertDatabaseMissing('audit_logs', [
            'action' => 'attendance.check_in',
        ]);
    }

    // --- Cross-midnight behavior ---

    public function test_domain_engine_cross_midnight_resolves_previous_date(): void
    {
        $employee = $this->makeEmployee([
            'employment_status' => EmploymentStatus::Permanent->value,
            'end_date' => null,
        ]);

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

        $engine = $this->makeEngine();

        $checkInAt = CarbonImmutable::create(2026, 9, 11, 22, 5, 0);
        $workLocation = $this->makeWorkLocation();

        $data = new AttendanceOperationData(
            employeeId: $employee->id,
            latitude: -6.2001,
            longitude: 106.8001,
            accuracy: 12.5,
            deviceIdentifier: 'test-device',
            source: 'mobile',
            workLocationId: $workLocation->id,
            occurredAt: $checkInAt,
            eventType: AttendanceEventType::CheckIn,
        );

        $result = $engine->checkIn($employee, $data);

        $this->assertSame('2026-09-11', $result->attendanceRecord->attendance_date->format('Y-m-d'));
    }

    // --- Open session guard ---

    public function test_domain_engine_throws_when_open_session_exists(): void
    {
        $employee = $this->makeEmployee([
            'employment_status' => EmploymentStatus::Permanent->value,
            'end_date' => null,
        ]);
        $this->makeSchedule($employee);
        $this->makePolicy($employee);
        $engine = $this->makeEngine();

        $data = $this->makeOperationData($employee, CarbonImmutable::now());
        $engine->checkIn($employee, $data);

        $this->expectException(AttendanceAlreadyCheckedInException::class);

        $engine->checkIn($employee, $data);
    }
}
