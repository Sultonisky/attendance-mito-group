<?php

namespace Tests\Feature\Penalty;

use App\Actions\Penalty\CalculateSystemPenalty;
use App\Domain\Penalty\Engines\PenaltyEngine;
use App\Domain\Policy\Engines\PolicyEngine;
use App\Domain\Schedule\Engines\ScheduleEngine;
use App\Enums\EmploymentStatus;
use App\Enums\PenaltyStatus;
use App\Enums\PenaltyViolationType;
use App\Enums\RecordStatus;
use App\Models\AttendanceRecord;
use App\Models\AttendanceSession;
use App\Models\Employee;
use App\Models\LeaveRequest;
use App\Models\PermissionRequest;
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

class SystemPenaltyTest extends TestCase
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

    private function makePolicy(Employee $employee): Policy
    {
        $policy = PolicyFactory::new()->create(['status' => RecordStatus::Active->value]);
        PolicyAssignmentFactory::new()->forEmployee($employee)->forPolicy($policy)->create([
            'effective_from' => '2026-01-01',
            'effective_to' => '2026-12-31',
        ]);

        return $policy;
    }

    private function makeSchedule(Employee $employee): WorkSchedule
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

    public function test_system_absence_creates_penalty(): void
    {
        [$user, $employee] = $this->makeActiveUserAndEmployee();
        $date = '2026-09-10';
        $this->makePolicy($employee);
        $this->makeSchedule($employee);

        PenaltyRuleFactory::new()->forViolation('ABSENCE', null, 5)->create();

        $action = new CalculateSystemPenalty($this->makeEngine());
        $records = $action->execute($employee, CarbonImmutable::parse($date));

        $this->assertCount(1, $records);
        $this->assertSame(PenaltyStatus::Applied->value, $records[0]->status);
        $this->assertSame('SYSTEM', $records[0]->source);
        $this->assertSame(PenaltyViolationType::Absence->value, $records[0]->violation_type);
        $this->assertSame(5.0, (float) $records[0]->final_points);
    }

    public function test_system_penalty_is_immediately_applied(): void
    {
        [$user, $employee] = $this->makeActiveUserAndEmployee();
        $date = '2026-09-10';
        $this->makePolicy($employee);
        $this->makeSchedule($employee);

        PenaltyRuleFactory::new()->forViolation('ABSENCE', null, 5)->create();

        $action = new CalculateSystemPenalty($this->makeEngine());
        $records = $action->execute($employee, CarbonImmutable::parse($date));

        $this->assertSame(PenaltyStatus::Applied->value, $records[0]->status);
    }

    public function test_no_system_penalty_on_approved_leave(): void
    {
        [$user, $employee] = $this->makeActiveUserAndEmployee();
        $date = '2026-09-10';
        $this->makePolicy($employee);
        $this->makeSchedule($employee);

        PenaltyRuleFactory::new()->forViolation('ABSENCE', null, 5)->create();

        LeaveRequestFactory::new()->forEmployee($employee)->approved()->create([
            'start_date' => '2026-09-01',
            'end_date' => '2026-09-30',
        ]);

        $action = new CalculateSystemPenalty($this->makeEngine());
        $records = $action->execute($employee, CarbonImmutable::parse($date));

        $this->assertCount(0, $records);
    }

    public function test_no_system_penalty_on_approved_business_trip(): void
    {
        [$user, $employee] = $this->makeActiveUserAndEmployee();
        $date = '2026-09-10';
        $this->makePolicy($employee);
        $this->makeSchedule($employee);

        PenaltyRuleFactory::new()->forViolation('ABSENCE', null, 5)->create();

        PermissionRequestFactory::new()->forEmployee($employee)->approved()->business()->create([
            'start_at' => '2026-09-01 00:00:00',
            'end_at' => '2026-09-30 23:59:59',
        ]);

        $action = new CalculateSystemPenalty($this->makeEngine());
        $records = $action->execute($employee, CarbonImmutable::parse($date));

        $this->assertCount(0, $records);
    }

    public function test_system_penalty_idempotent_on_retry(): void
    {
        [$user, $employee] = $this->makeActiveUserAndEmployee();
        $date = '2026-09-10';
        $this->makePolicy($employee);
        $this->makeSchedule($employee);

        PenaltyRuleFactory::new()->forViolation('ABSENCE', null, 5)->create();

        $action = new CalculateSystemPenalty($this->makeEngine());

        $records1 = $action->execute($employee, CarbonImmutable::parse($date));
        $records2 = $action->execute($employee, CarbonImmutable::parse($date));

        $this->assertCount(1, $records1);
        $this->assertCount(1, $records2);
        $this->assertSame($records1[0]->id, $records2[0]->id);
    }

    public function test_leave_check_failure_prevents_penalty_mutation(): void
    {
        [$user, $employee] = $this->makeActiveUserAndEmployee();
        $date = '2026-09-10';
        $this->makePolicy($employee);
        $this->makeSchedule($employee);

        PenaltyRuleFactory::new()->forViolation('ABSENCE', null, 5)->create();

        $engine = $this->makeEngine();
        $violations = $engine->evaluate($employee, CarbonImmutable::parse($date));
        $this->assertCount(1, $violations);
    }

    public function test_system_late_creates_penalty_when_checkin_after_schedule(): void
    {
        [$user, $employee] = $this->makeActiveUserAndEmployee();
        $date = '2026-09-10';
        $this->makePolicy($employee);
        $this->makeSchedule($employee);

        PenaltyRuleFactory::new()->forViolation('LATE', 5, 3)->create();

        $attendance = AttendanceRecord::create([
            'employee_id' => $employee->id,
            'attendance_date' => $date,
            'status' => 'present',
        ]);
        AttendanceSession::create([
            'attendance_record_id' => $attendance->id,
            'check_in_at' => '2026-09-10 08:20:00',
            'check_out_at' => '2026-09-10 17:00:00',
            'duration_minutes' => 520,
            'status' => 'closed',
        ]);

        $action = new CalculateSystemPenalty($this->makeEngine());
        $records = $action->execute($employee, CarbonImmutable::parse($date));

        $this->assertCount(1, $records);
        $this->assertSame(PenaltyViolationType::Late->value, $records[0]->violation_type);
        $this->assertSame(3.0, (float) $records[0]->final_points);
    }

    public function test_system_late_respects_grace_period(): void
    {
        [$user, $employee] = $this->makeActiveUserAndEmployee();
        $date = '2026-09-10';
        $policy = $this->makePolicy($employee);
        $this->makeSchedule($employee);

        $policy->update([
            'configuration' => array_merge($policy->configuration, ['grace_period_minutes' => 10]),
        ]);

        PenaltyRuleFactory::new()->forViolation('LATE', 5, 3)->create();

        $attendance = AttendanceRecord::create([
            'employee_id' => $employee->id,
            'attendance_date' => $date,
            'status' => 'present',
        ]);
        AttendanceSession::create([
            'attendance_record_id' => $attendance->id,
            'check_in_at' => '2026-09-10 08:12:00',
            'check_out_at' => '2026-09-10 17:00:00',
            'duration_minutes' => 528,
            'status' => 'closed',
        ]);

        $action = new CalculateSystemPenalty($this->makeEngine());
        $records = $action->execute($employee, CarbonImmutable::parse($date));

        $this->assertCount(0, $records);
    }

    public function test_system_late_threshold_below_effective_late_qualifies(): void
    {
        [$user, $employee] = $this->makeActiveUserAndEmployee();
        $date = '2026-09-10';
        $this->makePolicy($employee);
        $this->makeSchedule($employee);

        PenaltyRuleFactory::new()->forViolation('LATE', 10, 3)->create();

        $attendance = AttendanceRecord::create([
            'employee_id' => $employee->id,
            'attendance_date' => $date,
            'status' => 'present',
        ]);
        AttendanceSession::create([
            'attendance_record_id' => $attendance->id,
            'check_in_at' => '2026-09-10 08:20:00',
            'check_out_at' => '2026-09-10 17:00:00',
            'duration_minutes' => 520,
            'status' => 'closed',
        ]);

        $action = new CalculateSystemPenalty($this->makeEngine());
        $records = $action->execute($employee, CarbonImmutable::parse($date));

        $this->assertCount(1, $records);
    }

    public function test_system_late_threshold_above_effective_late_does_not_qualify(): void
    {
        [$user, $employee] = $this->makeActiveUserAndEmployee();
        $date = '2026-09-10';
        $this->makePolicy($employee);
        $this->makeSchedule($employee);

        PenaltyRuleFactory::new()->forViolation('LATE', 30, 3)->create();

        $attendance = AttendanceRecord::create([
            'employee_id' => $employee->id,
            'attendance_date' => $date,
            'status' => 'present',
        ]);
        AttendanceSession::create([
            'attendance_record_id' => $attendance->id,
            'check_in_at' => '2026-09-10 08:20:00',
            'check_out_at' => '2026-09-10 17:00:00',
            'duration_minutes' => 520,
            'status' => 'closed',
        ]);

        $action = new CalculateSystemPenalty($this->makeEngine());
        $records = $action->execute($employee, CarbonImmutable::parse($date));

        $this->assertCount(0, $records);
    }

    public function test_system_early_checkout_creates_penalty_when_checkout_before_schedule(): void
    {
        [$user, $employee] = $this->makeActiveUserAndEmployee();
        $date = '2026-09-10';
        $this->makePolicy($employee);
        $this->makeSchedule($employee);

        PenaltyRuleFactory::new()->forViolation('EARLY_CHECKOUT', 20, 4)->create();

        $attendance = AttendanceRecord::create([
            'employee_id' => $employee->id,
            'attendance_date' => $date,
            'status' => 'present',
        ]);
        AttendanceSession::create([
            'attendance_record_id' => $attendance->id,
            'check_in_at' => '2026-09-10 08:00:00',
            'check_out_at' => '2026-09-10 16:30:00',
            'duration_minutes' => 510,
            'status' => 'closed',
        ]);

        $action = new CalculateSystemPenalty($this->makeEngine());
        $records = $action->execute($employee, CarbonImmutable::parse($date));

        $this->assertCount(1, $records);
        $this->assertSame(PenaltyViolationType::EarlyCheckout->value, $records[0]->violation_type);
        $this->assertSame(4.0, (float) $records[0]->final_points);
    }

    public function test_system_early_checkout_respects_grace_period(): void
    {
        [$user, $employee] = $this->makeActiveUserAndEmployee();
        $date = '2026-09-10';
        $policy = $this->makePolicy($employee);
        $this->makeSchedule($employee);

        $policy->update([
            'configuration' => array_merge($policy->configuration, ['grace_period_minutes' => 10]),
        ]);

        PenaltyRuleFactory::new()->forViolation('EARLY_CHECKOUT', 20, 4)->create();

        $attendance = AttendanceRecord::create([
            'employee_id' => $employee->id,
            'attendance_date' => $date,
            'status' => 'present',
        ]);
        AttendanceSession::create([
            'attendance_record_id' => $attendance->id,
            'check_in_at' => '2026-09-10 08:00:00',
            'check_out_at' => '2026-09-10 16:50:00',
            'duration_minutes' => 530,
            'status' => 'closed',
        ]);

        $action = new CalculateSystemPenalty($this->makeEngine());
        $records = $action->execute($employee, CarbonImmutable::parse($date));

        $this->assertCount(0, $records);
    }

    public function test_system_incomplete_attendance_creates_penalty_when_session_open(): void
    {
        [$user, $employee] = $this->makeActiveUserAndEmployee();
        $date = '2026-09-10';
        $this->makePolicy($employee);
        $this->makeSchedule($employee);

        PenaltyRuleFactory::new()->forViolation('INCOMPLETE_ATTENDANCE', null, 2)->create();

        $attendance = AttendanceRecord::create([
            'employee_id' => $employee->id,
            'attendance_date' => $date,
            'status' => 'incomplete',
        ]);
        AttendanceSession::create([
            'attendance_record_id' => $attendance->id,
            'check_in_at' => '2026-09-10 08:00:00',
            'check_out_at' => null,
            'duration_minutes' => null,
            'status' => 'open',
        ]);

        $action = new CalculateSystemPenalty($this->makeEngine());
        $records = $action->execute($employee, CarbonImmutable::parse($date));

        $this->assertCount(1, $records);
        $this->assertSame(PenaltyViolationType::IncompleteAttendance->value, $records[0]->violation_type);
        $this->assertSame(2.0, (float) $records[0]->final_points);
    }

    public function test_system_incomplete_attendance_does_not_create_absence_when_session_open(): void
    {
        [$user, $employee] = $this->makeActiveUserAndEmployee();
        $date = '2026-09-10';
        $this->makePolicy($employee);
        $this->makeSchedule($employee);

        PenaltyRuleFactory::new()->forViolation('ABSENCE', null, 5)->create();
        PenaltyRuleFactory::new()->forViolation('INCOMPLETE_ATTENDANCE', null, 2)->create();

        $attendance = AttendanceRecord::create([
            'employee_id' => $employee->id,
            'attendance_date' => $date,
            'status' => 'incomplete',
        ]);
        AttendanceSession::create([
            'attendance_record_id' => $attendance->id,
            'check_in_at' => '2026-09-10 08:00:00',
            'check_out_at' => null,
            'duration_minutes' => null,
            'status' => 'open',
        ]);

        $action = new CalculateSystemPenalty($this->makeEngine());
        $records = $action->execute($employee, CarbonImmutable::parse($date));

        $this->assertCount(1, $records);
        $this->assertSame(PenaltyViolationType::IncompleteAttendance->value, $records[0]->violation_type);
    }

    public function test_system_late_uses_first_checkin_with_multiple_sessions(): void
    {
        [$user, $employee] = $this->makeActiveUserAndEmployee();
        $date = '2026-09-10';
        $this->makePolicy($employee);
        $this->makeSchedule($employee);

        PenaltyRuleFactory::new()->forViolation('LATE', 5, 3)->create();

        $attendance = AttendanceRecord::create([
            'employee_id' => $employee->id,
            'attendance_date' => $date,
            'status' => 'present',
        ]);
        AttendanceSession::create([
            'attendance_record_id' => $attendance->id,
            'check_in_at' => '2026-09-10 08:05:00',
            'check_out_at' => '2026-09-10 12:00:00',
            'duration_minutes' => 235,
            'status' => 'closed',
        ]);
        AttendanceSession::create([
            'attendance_record_id' => $attendance->id,
            'check_in_at' => '2026-09-10 13:00:00',
            'check_out_at' => '2026-09-10 17:00:00',
            'duration_minutes' => 240,
            'status' => 'closed',
        ]);

        $action = new CalculateSystemPenalty($this->makeEngine());
        $records = $action->execute($employee, CarbonImmutable::parse($date));

        $this->assertCount(1, $records);
        $this->assertSame(PenaltyViolationType::Late->value, $records[0]->violation_type);
    }

    public function test_system_early_checkout_uses_final_checkout_with_multiple_sessions(): void
    {
        [$user, $employee] = $this->makeActiveUserAndEmployee();
        $date = '2026-09-10';
        $this->makePolicy($employee);
        $this->makeSchedule($employee);

        PenaltyRuleFactory::new()->forViolation('EARLY_CHECKOUT', 5, 4)->create();

        $attendance = AttendanceRecord::create([
            'employee_id' => $employee->id,
            'attendance_date' => $date,
            'status' => 'present',
        ]);
        AttendanceSession::create([
            'attendance_record_id' => $attendance->id,
            'check_in_at' => '2026-09-10 08:00:00',
            'check_out_at' => '2026-09-10 12:00:00',
            'duration_minutes' => 240,
            'status' => 'closed',
        ]);
        AttendanceSession::create([
            'attendance_record_id' => $attendance->id,
            'check_in_at' => '2026-09-10 13:00:00',
            'check_out_at' => '2026-09-10 16:30:00',
            'duration_minutes' => 210,
            'status' => 'closed',
        ]);

        $action = new CalculateSystemPenalty($this->makeEngine());
        $records = $action->execute($employee, CarbonImmutable::parse($date));

        $this->assertCount(1, $records);
        $this->assertSame(PenaltyViolationType::EarlyCheckout->value, $records[0]->violation_type);
    }

    public function test_no_system_penalty_on_rejected_leave(): void
    {
        [$user, $employee] = $this->makeActiveUserAndEmployee();
        $date = '2026-09-10';
        $this->makePolicy($employee);
        $this->makeSchedule($employee);

        PenaltyRuleFactory::new()->forViolation('ABSENCE', null, 5)->create();

        LeaveRequestFactory::new()->forEmployee($employee)->rejected()->create([
            'start_date' => '2026-09-01',
            'end_date' => '2026-09-30',
        ]);

        $action = new CalculateSystemPenalty($this->makeEngine());
        $records = $action->execute($employee, CarbonImmutable::parse($date));

        $this->assertCount(1, $records);
    }

    public function test_no_system_penalty_on_cancelled_leave(): void
    {
        [$user, $employee] = $this->makeActiveUserAndEmployee();
        $date = '2026-09-10';
        $this->makePolicy($employee);
        $this->makeSchedule($employee);

        PenaltyRuleFactory::new()->forViolation('ABSENCE', null, 5)->create();

        LeaveRequestFactory::new()->forEmployee($employee)->cancelled()->create([
            'start_date' => '2026-09-01',
            'end_date' => '2026-09-30',
        ]);

        $action = new CalculateSystemPenalty($this->makeEngine());
        $records = $action->execute($employee, CarbonImmutable::parse($date));

        $this->assertCount(1, $records);
    }
}
