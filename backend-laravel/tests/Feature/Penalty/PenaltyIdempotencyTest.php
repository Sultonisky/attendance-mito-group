<?php

namespace Tests\Feature\Penalty;

use App\Actions\Penalty\CalculateSystemPenalty;
use App\Domain\Penalty\Engines\PenaltyEngine;
use App\Domain\Policy\Engines\PolicyEngine;
use App\Domain\Schedule\Engines\ScheduleEngine;
use App\Enums\EmploymentStatus;
use App\Enums\RecordStatus;
use App\Models\Employee;
use App\Models\PenaltyRecord;
use App\Models\PenaltyRule;
use App\Models\Policy;
use App\Models\PolicyAssignment;
use App\Models\ScheduleAssignment;
use App\Models\Shift;
use App\Models\User;
use App\Models\WorkSchedule;
use Carbon\CarbonImmutable;
use Database\Factories\EmployeeFactory;
use Database\Factories\PenaltyRuleFactory;
use Database\Factories\PolicyAssignmentFactory;
use Database\Factories\PolicyFactory;
use Database\Factories\ScheduleAssignmentFactory;
use Database\Factories\ShiftFactory;
use Database\Factories\UserFactory;
use Database\Factories\WorkScheduleFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PenaltyIdempotencyTest extends TestCase
{
    use RefreshDatabase;

    public function test_retrying_same_system_penalty_does_not_create_duplicates(): void
    {
        $user = UserFactory::new()->create();
        $employee = EmployeeFactory::new()->create([
            'employment_status' => EmploymentStatus::Permanent->value,
            'user_id' => $user->id,
        ]);
        $date = '2026-09-10';

        $policy = PolicyFactory::new()->create(['status' => RecordStatus::Active->value]);
        PolicyAssignmentFactory::new()->forEmployee($employee)->forPolicy($policy)->create([
            'effective_from' => '2026-01-01',
            'effective_to' => '2026-12-31',
        ]);

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

        PenaltyRuleFactory::new()->forViolation('ABSENCE', null, 5)->create();

        $action = new CalculateSystemPenalty(new PenaltyEngine(
            new PolicyEngine(),
            new ScheduleEngine(),
        ));

        $records1 = $action->execute($employee, CarbonImmutable::parse($date));
        $records2 = $action->execute($employee, CarbonImmutable::parse($date));

        $this->assertCount(1, $records1);
        $this->assertCount(1, $records2);
        $this->assertSame($records1[0]->id, $records2[0]->id);
        $this->assertSame(1, PenaltyRecord::where('employee_id', $employee->id)->count());
    }
}
