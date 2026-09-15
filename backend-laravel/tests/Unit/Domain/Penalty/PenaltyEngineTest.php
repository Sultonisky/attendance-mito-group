<?php

namespace Tests\Unit\Domain\Penalty;

use App\Domain\Penalty\DTOs\PenaltyViolationData;
use App\Domain\Penalty\Engines\PenaltyEngine;
use App\Domain\Policy\Engines\PolicyEngine;
use App\Domain\Schedule\Engines\ScheduleEngine;
use App\Enums\EmploymentStatus;
use App\Enums\PenaltyViolationType;
use App\Enums\RecordStatus;
use App\Models\Employee;
use App\Models\LeaveRequest;
use App\Models\PenaltyRule;
use App\Models\PermissionRequest;
use App\Models\Policy;
use App\Models\PolicyAssignment;
use App\Models\ScheduleAssignment;
use App\Models\Shift;
use App\Models\User;
use App\Models\WorkSchedule;
use Carbon\CarbonImmutable;
use Database\Factories\EmployeeFactory;
use Database\Factories\LeaveRequestFactory;
use Database\Factories\PenaltyRuleFactory;
use Database\Factories\PermissionRequestFactory;
use Database\Factories\PolicyAssignmentFactory;
use Database\Factories\PolicyFactory;
use Database\Factories\ScheduleAssignmentFactory;
use Database\Factories\ShiftFactory;
use Database\Factories\UserFactory;
use Database\Factories\WorkScheduleFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PenaltyEngineTest extends TestCase
{
    use RefreshDatabase;

    private function makeActiveUserAndEmployee(): array
    {
        $user = UserFactory::new()->create();
        $employee = EmployeeFactory::new()->create([
            'employment_status' => EmploymentStatus::Permanent->value,
            'user_id' => $user->id,
        ]);

        return [$user, $employee];
    }

    private function makePolicy(Employee $employee, string $date): Policy
    {
        $policy = PolicyFactory::new()->create(['status' => RecordStatus::Active->value]);
        // Use a wider date range to avoid SQLite date comparison edge cases
        PolicyAssignmentFactory::new()->forEmployee($employee)->forPolicy($policy)->create([
            'effective_from' => '2026-01-01',
            'effective_to' => '2026-12-31',
        ]);

        return $policy;
    }

    private function makeSchedule(Employee $employee, string $date): WorkSchedule
    {
        $schedule = WorkScheduleFactory::new()->create(['status' => RecordStatus::Active->value]);
        ShiftFactory::new()->forSchedule($schedule)->create([
            'start_time' => '08:00:00',
            'end_time' => '17:00:00',
            'cross_midnight' => false,
        ]);
        ScheduleAssignmentFactory::new()->forSchedule($schedule)->create([
            'employee_id' => $employee->id,
            'effective_from' => '2026-01-01',
            'effective_to' => '2026-12-31',
        ]);

        return $schedule;
    }

    private function makeEngine(): PenaltyEngine
    {
        return new PenaltyEngine(
            new PolicyEngine(),
            new ScheduleEngine(),
        );
    }

    public function test_absence_qualified_when_no_attendance_on_working_day(): void
    {
        [$user, $employee] = $this->makeActiveUserAndEmployee();
        $date = '2026-09-10';
        $this->makePolicy($employee, $date);
        $this->makeSchedule($employee, $date);

        PenaltyRuleFactory::new()->forViolation('ABSENCE', null, 5)->create();

        $engine = $this->makeEngine();
        $violations = $engine->evaluate($employee, CarbonImmutable::parse($date));

        $this->assertCount(1, $violations);
        $this->assertSame(PenaltyViolationType::Absence, $violations[0]->violationType);
        $this->assertSame(5.0, $violations[0]->points);
    }

    public function test_no_penalty_when_no_schedule(): void
    {
        [$user, $employee] = $this->makeActiveUserAndEmployee();
        $date = '2026-09-10';
        $this->makePolicy($employee, $date);
        // No schedule assignment

        PenaltyRuleFactory::new()->forViolation('ABSENCE', null, 5)->create();

        $engine = $this->makeEngine();
        $violations = $engine->evaluate($employee, CarbonImmutable::parse($date));

        $this->assertCount(0, $violations);
    }

    public function test_no_penalty_when_on_approved_leave(): void
    {
        [$user, $employee] = $this->makeActiveUserAndEmployee();
        $date = '2026-09-10';
        $this->makePolicy($employee, $date);
        $this->makeSchedule($employee, $date);

        PenaltyRuleFactory::new()->forViolation('ABSENCE', null, 5)->create();

        LeaveRequestFactory::new()->forEmployee($employee)->approved()->create([
            'start_date' => '2026-09-01',
            'end_date' => '2026-09-30',
        ]);

        $engine = $this->makeEngine();
        $violations = $engine->evaluate($employee, CarbonImmutable::parse($date));

        $this->assertCount(0, $violations);
    }

    public function test_no_penalty_when_on_approved_business_trip(): void
    {
        [$user, $employee] = $this->makeActiveUserAndEmployee();
        $date = '2026-09-10';
        $this->makePolicy($employee, $date);
        $this->makeSchedule($employee, $date);

        PenaltyRuleFactory::new()->forViolation('ABSENCE', null, 5)->create();

        PermissionRequestFactory::new()->forEmployee($employee)->approved()->business()->create([
            'start_at' => '2026-09-01 00:00:00',
            'end_at' => '2026-09-30 23:59:59',
        ]);

        $engine = $this->makeEngine();
        $violations = $engine->evaluate($employee, CarbonImmutable::parse($date));

        $this->assertCount(0, $violations);
    }

    public function test_no_penalty_for_pending_leave(): void
    {
        [$user, $employee] = $this->makeActiveUserAndEmployee();
        $date = '2026-09-10';
        $this->makePolicy($employee, $date);
        $this->makeSchedule($employee, $date);

        PenaltyRuleFactory::new()->forViolation('ABSENCE', null, 5)->create();

        LeaveRequestFactory::new()->forEmployee($employee)->create([
            'start_date' => '2026-09-01',
            'end_date' => '2026-09-30',
            'status' => 'pending',
        ]);

        $engine = $this->makeEngine();
        $violations = $engine->evaluate($employee, CarbonImmutable::parse($date));

        $this->assertCount(1, $violations);
        $this->assertSame(PenaltyViolationType::Absence, $violations[0]->violationType);
    }
}
