<?php

namespace Tests\Unit\Domain\Policy;

use App\Domain\Policy\Engines\PolicyEngine;
use App\Domain\Policy\Exceptions\AmbiguousPolicyAssignmentException;
use App\Domain\Policy\Exceptions\InactivePolicyException;
use App\Enums\PolicyStatus;
use App\Models\Employee;
use App\Models\Policy;
use App\Models\PolicyAssignment;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PolicyEngineTest extends TestCase
{
    use RefreshDatabase;

    private PolicyEngine $engine;

    protected function setUp(): void
    {
        parent::setUp();
        $this->engine = new PolicyEngine;
    }

    private function makeEmployee(): Employee
    {
        return Employee::factory()->create();
    }

    private function makePolicy(array $overrides = []): Policy
    {
        return Policy::factory()->create($overrides);
    }

    private function makeAssignment(Employee $employee, Policy $policy, array $overrides = []): PolicyAssignment
    {
        return PolicyAssignment::factory()->forEmployee($employee)->forPolicy($policy)->create($overrides);
    }

    public function test_active_policy_resolves(): void
    {
        $employee = $this->makeEmployee();
        $policy = $this->makePolicy(['status' => PolicyStatus::Active->value]);
        $date = CarbonImmutable::parse('2026-06-15');

        $this->makeAssignment($employee, $policy, [
            'effective_from' => '2026-01-01',
            'effective_to' => '2026-12-31',
        ]);

        $result = $this->engine->resolve($employee, $date);

        $this->assertSame($employee->id, $result->employeeId);
        $this->assertSame('2026-06-15', $result->date->toDateString());
        $this->assertTrue($result->hasPolicy());
        $this->assertSame($policy->id, $result->policy->id);
    }

    public function test_no_assignment_returns_no_policy(): void
    {
        $employee = $this->makeEmployee();
        $date = CarbonImmutable::parse('2026-06-15');

        $result = $this->engine->resolve($employee, $date);

        $this->assertFalse($result->hasPolicy());
        $this->assertNull($result->policy);
    }

    public function test_effective_from_respected_future_assignment_not_applied(): void
    {
        $employee = $this->makeEmployee();
        $policy = $this->makePolicy();
        $pastDate = CarbonImmutable::parse('2026-03-01');

        $this->makeAssignment($employee, $policy, [
            'effective_from' => '2026-07-01',
            'effective_to' => '2026-12-31',
        ]);

        $result = $this->engine->resolve($employee, $pastDate);

        $this->assertFalse($result->hasPolicy());
    }

    public function test_effective_to_respected_expired_assignment_not_applied(): void
    {
        $employee = $this->makeEmployee();
        $policy = $this->makePolicy();
        $futureDate = CarbonImmutable::parse('2026-08-01');

        $this->makeAssignment($employee, $policy, [
            'effective_from' => '2026-01-01',
            'effective_to' => '2026-06-30',
        ]);

        $result = $this->engine->resolve($employee, $futureDate);

        $this->assertFalse($result->hasPolicy());
    }

    public function test_open_ended_assignment_resolves(): void
    {
        $employee = $this->makeEmployee();
        $policy = $this->makePolicy();
        $date = CarbonImmutable::parse('2026-12-31');

        $this->makeAssignment($employee, $policy, [
            'effective_from' => '2026-01-01',
            'effective_to' => null,
        ]);

        $result = $this->engine->resolve($employee, $date);

        $this->assertTrue($result->hasPolicy());
        $this->assertSame($policy->id, $result->policy->id);
    }

    public function test_inactive_policy_throws_exception(): void
    {
        $employee = $this->makeEmployee();
        $policy = $this->makePolicy(['status' => PolicyStatus::Draft->value]);
        $date = CarbonImmutable::parse('2026-06-15');

        $this->makeAssignment($employee, $policy, [
            'effective_from' => '2026-01-01',
            'effective_to' => '2026-12-31',
        ]);

        $this->expectException(InactivePolicyException::class);
        $this->engine->resolve($employee, $date);
    }

    public function test_ambiguous_overlapping_assignments_throw_exception(): void
    {
        $employee = $this->makeEmployee();
        $policyA = $this->makePolicy();
        $policyB = $this->makePolicy();
        $date = CarbonImmutable::parse('2026-06-15');

        $this->makeAssignment($employee, $policyA, [
            'effective_from' => '2026-01-01',
            'effective_to' => '2026-12-31',
        ]);
        $this->makeAssignment($employee, $policyB, [
            'effective_from' => '2026-04-01',
            'effective_to' => '2026-10-01',
        ]);

        $this->expectException(AmbiguousPolicyAssignmentException::class);
        $this->engine->resolve($employee, $date);
    }

    public function test_historical_resolution_returns_correct_policy(): void
    {
        $employee = $this->makeEmployee();
        $oldPolicy = $this->makePolicy(['name' => 'Old Policy']);
        $newPolicy = $this->makePolicy(['name' => 'New Policy']);
        $historicalDate = CarbonImmutable::parse('2026-03-15');
        $currentDate = CarbonImmutable::parse('2026-08-15');

        $this->makeAssignment($employee, $oldPolicy, [
            'effective_from' => '2026-01-01',
            'effective_to' => '2026-06-30',
        ]);
        $this->makeAssignment($employee, $newPolicy, [
            'effective_from' => '2026-07-01',
            'effective_to' => null,
        ]);

        $oldResult = $this->engine->resolve($employee, $historicalDate);
        $newResult = $this->engine->resolve($employee, $currentDate);

        $this->assertSame('Old Policy', $oldResult->policy->name);
        $this->assertSame('New Policy', $newResult->policy->name);
    }

    public function test_future_assignment_does_not_override_current(): void
    {
        $employee = $this->makeEmployee();
        $currentPolicy = $this->makePolicy(['name' => 'Current']);
        $futurePolicy = $this->makePolicy(['name' => 'Future']);
        $currentDate = CarbonImmutable::parse('2026-09-15');

        $this->makeAssignment($employee, $currentPolicy, [
            'effective_from' => '2026-01-01',
            'effective_to' => '2026-09-30',
        ]);
        $this->makeAssignment($employee, $futurePolicy, [
            'effective_from' => '2026-10-01',
            'effective_to' => null,
        ]);

        $result = $this->engine->resolve($employee, $currentDate);

        $this->assertTrue($result->hasPolicy());
        $this->assertSame('Current', $result->policy->name);
    }
}
