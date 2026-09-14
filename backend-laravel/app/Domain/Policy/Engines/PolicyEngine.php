<?php

namespace App\Domain\Policy\Engines;

use App\Domain\Policy\DTOs\PolicyResolutionData;
use App\Domain\Policy\Exceptions\AmbiguousPolicyAssignmentException;
use App\Domain\Policy\Exceptions\InactivePolicyException;
use App\Enums\PolicyStatus;
use App\Models\Employee;
use App\Models\PolicyAssignment;
use Carbon\CarbonImmutable;

/**
 * Resolves the applicable attendance policy for an employee on a given date.
 *
 * Resolution rules:
 *
 * 1. Find policy assignments where the date falls within [effective_from, effective_to].
 * 2. If zero assignments are found, return an explicit no-policy result.
 * 3. If more than one assignment overlaps, throw AmbiguousPolicyAssignmentException.
 * 4. If the assigned policy is not active, throw InactivePolicyException.
 * 5. Otherwise, return the active policy.
 */
class PolicyEngine
{
    /**
     * Resolve the policy applicable to the employee on the given date.
     */
    public function resolve(Employee $employee, CarbonImmutable $date): PolicyResolutionData
    {
        $assignments = PolicyAssignment::where('employee_id', $employee->id)
            ->where('effective_from', '<=', $date->toDateString())
            ->where(function ($query) use ($date): void {
                $query->whereNull('effective_to')
                    ->orWhere('effective_to', '>=', $date->toDateString());
            })
            ->with('policy')
            ->get();

        if ($assignments->isEmpty()) {
            return new PolicyResolutionData(
                employeeId: $employee->id,
                date: $date,
                policy: null,
            );
        }

        if ($assignments->count() > 1) {
            throw new AmbiguousPolicyAssignmentException(
                'Multiple policy assignments overlap for employee '.$employee->id.' on '.$date->toDateString().'.'
            );
        }

        $assignment = $assignments->first();
        $policy = $assignment->policy;

        if ($policy === null || $policy->status !== PolicyStatus::Active->value) {
            throw new InactivePolicyException(
                'Policy assignment for employee '.$employee->id.' on '.$date->toDateString().' references an inactive policy.'
            );
        }

        return new PolicyResolutionData(
            employeeId: $employee->id,
            date: $date,
            policy: $policy,
        );
    }
}
