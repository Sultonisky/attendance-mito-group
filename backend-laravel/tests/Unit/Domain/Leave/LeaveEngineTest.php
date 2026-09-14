<?php

namespace Tests\Unit\Domain\Leave;

use App\Domain\Leave\Engines\LeaveEngine;
use App\Domain\Leave\Exceptions\InvalidLeaveStateException;
use App\Domain\Leave\Rules\LeaveDateRule;
use App\Domain\Leave\Rules\LeaveStateRule;
use App\Models\Employee;
use App\Models\LeaveType;
use Carbon\CarbonImmutable;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LeaveEngineTest extends TestCase
{
    use RefreshDatabase;

    private LeaveEngine $engine;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->engine = app(LeaveEngine::class);
    }

    private function employee(string $joinDate, array $overrides = []): Employee
    {
        return Employee::factory()->create(array_merge(['join_date' => $joinDate], $overrides));
    }

    private function annualType(): LeaveType
    {
        return LeaveType::factory()->annual()->create();
    }

    public function test_employee_under_six_months_is_not_eligible(): void
    {
        $employee = $this->employee('2026-01-01');
        $result = $this->engine->resolveEligibility($employee, CarbonImmutable::parse('2026-06-30'));

        $this->assertFalse($result->eligible);
        $this->assertSame('2026-07-01', $result->eligibilityDate->toDateString());
    }

    public function test_employee_exactly_at_six_months_is_eligible(): void
    {
        $employee = $this->employee('2026-01-01');
        $result = $this->engine->resolveEligibility($employee, CarbonImmutable::parse('2026-07-01'));

        $this->assertTrue($result->eligible);
    }

    public function test_employee_beyond_six_months_is_eligible(): void
    {
        $employee = $this->employee('2026-01-01');
        $result = $this->engine->resolveEligibility($employee, CarbonImmutable::parse('2026-09-14'));

        $this->assertTrue($result->eligible);
    }

    public function test_first_eligible_accrual_and_monthly_cycle(): void
    {
        $this->annualType();
        $employee = $this->employee('2026-01-15');

        $this->engine->accrue($employee, CarbonImmutable::parse('2026-07-15'));
        $this->assertSame(1.0, $this->engine->availableBalance($employee, $this->engine->annualLeaveType(), CarbonImmutable::parse('2026-07-15'))->available);

        $this->engine->accrue($employee, CarbonImmutable::parse('2026-09-15'));
        $this->assertSame(3.0, $this->engine->availableBalance($employee, $this->engine->annualLeaveType(), CarbonImmutable::parse('2026-09-15'))->available);
    }

    public function test_month_end_join_date_is_deterministic(): void
    {
        $join = CarbonImmutable::parse('2026-01-31');
        $this->assertSame('2026-02-28', $this->engine->accrualDateForMonth($join, 2026, 2)->toDateString());
        $this->assertSame('2026-07-31', $this->engine->eligibilityDate('2026-01-31')->toDateString());
    }

    public function test_month_end_join_date_clamps_to_leap_day(): void
    {
        // Regression (P9-HIGH-2): a Jan-31 join date must clamp to Feb 29 in a
        // leap year, never Feb 28.
        $join = CarbonImmutable::parse('2028-01-31');
        $this->assertSame('2028-02-29', $this->engine->accrualDateForMonth($join, 2028, 2)->toDateString());
        $this->assertSame('2028-12-31', $this->engine->accrualDateForMonth($join, 2028, 12)->toDateString());
        $this->assertSame('2028-07-31', $this->engine->eligibilityDate('2028-01-31')->toDateString());
    }

    public function test_duplicate_accrual_is_prevented(): void
    {
        $this->annualType();
        $employee = $this->employee('2026-01-01');
        $first = $this->engine->accrue($employee, CarbonImmutable::parse('2026-09-14'));
        $second = $this->engine->accrue($employee, CarbonImmutable::parse('2026-09-14'));

        $this->assertNotEmpty($first);
        $this->assertSame([], $second);
        $this->assertSame(3.0, $this->engine->availableBalance($employee, $this->engine->annualLeaveType(), CarbonImmutable::parse('2026-09-14'))->available);
    }

    public function test_expiration_is_idempotent_and_preserves_history(): void
    {
        $this->annualType();
        $employee = $this->employee('2025-01-01');
        $this->engine->accrue($employee, CarbonImmutable::parse('2025-07-01'));
        $this->assertSame(1.0, $this->engine->availableBalance($employee, $this->engine->annualLeaveType(), CarbonImmutable::parse('2025-07-01'))->available);

        $first = $this->engine->expire($employee, CarbonImmutable::parse('2026-07-02'));
        $second = $this->engine->expire($employee, CarbonImmutable::parse('2026-07-02'));

        $this->assertCount(1, $first);
        $this->assertSame([], $second);
        $this->assertSame(0.0, $this->engine->availableBalance($employee, $this->engine->annualLeaveType(), CarbonImmutable::parse('2026-07-02'))->available);
        $this->assertGreaterThanOrEqual(2, $employee->leaveBalances()->first()->transactions()->count());
    }

    public function test_state_transitions_are_deterministic(): void
    {
        $rule = app(LeaveStateRule::class);
        $this->assertTrue($rule->canTransition('pending', 'approved'));
        $this->assertTrue($rule->canTransition('approved', 'cancelled'));
        $this->assertFalse($rule->canTransition('rejected', 'approved'));
        $this->assertFalse($rule->canTransition('cancelled', 'approved'));
    }

    public function test_inclusive_duration_and_rejected_range(): void
    {
        $rule = app(LeaveDateRule::class);
        $this->assertSame(3, $rule->duration(CarbonImmutable::parse('2026-09-10'), CarbonImmutable::parse('2026-09-12'))->totalDays);
        $this->assertSame(1, $rule->duration(CarbonImmutable::parse('2026-09-10'), CarbonImmutable::parse('2026-09-10'))->totalDays);

        $this->expectException(InvalidLeaveStateException::class);
        $rule->duration(CarbonImmutable::parse('2026-09-12'), CarbonImmutable::parse('2026-09-10'));
    }

    public function test_inactive_employee_is_not_eligible(): void
    {
        $employee = $this->employee('2025-01-01', ['end_date' => '2026-01-01']);
        $result = $this->engine->resolveEligibility($employee, CarbonImmutable::parse('2026-09-14'));

        $this->assertFalse($result->eligible);
        $this->assertFalse($result->active);
    }
}
