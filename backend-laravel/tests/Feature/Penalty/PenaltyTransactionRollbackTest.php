<?php

namespace Tests\Feature\Penalty;

use App\Actions\Penalty\CalculateSystemPenalty;
use App\Domain\Penalty\Engines\PenaltyEngine;
use App\Domain\Policy\Engines\PolicyEngine;
use App\Domain\Schedule\Engines\ScheduleEngine;
use App\Enums\EmploymentStatus;
use App\Enums\RecordStatus;
use App\Models\PenaltyRecord;
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
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class PenaltyTransactionRollbackTest extends TestCase
{
    use RefreshDatabase;

    public function test_penalty_creation_rolls_back_when_audit_fails(): void
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

        DB::listen(function ($query) {
            if (str_contains($query->sql, 'insert into "audit_logs"')) {
                throw new \Exception('Intentional audit failure for rollback test');
            }
        });

        $action = new CalculateSystemPenalty(new PenaltyEngine(
            new PolicyEngine,
            new ScheduleEngine,
        ));

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Intentional audit failure for rollback test');

        $action->execute($employee, CarbonImmutable::parse($date));

        $this->assertSame(0, PenaltyRecord::where('employee_id', $employee->id)->count());
    }
}
