<?php

namespace App\Services\Attendance;

use App\Models\Employee;
use App\Models\Policy;
use App\Models\PolicyAssignment;
use Carbon\CarbonImmutable;

/**
 * Evaluate attendance policies for an employee on a specific date.
 *
 * Policies are stored as JSON configuration. The evaluator selects the active
 * policy assignments for the date and returns the merged configuration for
 * the caller to interpret. Business rule interpretation remains in the
 * AttendanceEngine / domain layer.
 */
class PolicyEvaluator
{
    /**
     * Get the active attendance policies for the employee on the given date.
     *
     * @return array<int, array{policy: Policy, configuration: array<string, mixed>}>
     */
    public function activeForDate(Employee $employee, CarbonImmutable $date): array
    {
        $assignments = PolicyAssignment::query()
            ->where('employee_id', $employee->id)
            ->where('effective_from', '<=', $date->toDateString())
            ->where(function ($query) use ($date) {
                $query->whereNull('effective_to')
                    ->orWhere('effective_to', '>=', $date->toDateString());
            })
            ->whereHas('policy', function ($query) {
                $query->where('status', 'active');
            })
            ->with('policy')
            ->orderByDesc('effective_from')
            ->get();

        return $assignments->map(function (PolicyAssignment $assignment) {
            $configuration = $assignment->policy->configuration ?? [];

            return [
                'policy' => $assignment->policy,
                'configuration' => is_array($configuration) ? $configuration : [],
            ];
        })->all();
    }

    /**
     * Evaluate whether the employee is allowed to check in based on active
     * policies.
     *
     * @return array{allowed: bool, reason: string|null, policies: array<int, array{policy: Policy, configuration: array<string, mixed>}>}
     */
    public function evaluateCheckIn(Employee $employee, CarbonImmutable $date): array
    {
        $policies = $this->activeForDate($employee, $date);

        foreach ($policies as $entry) {
            $configuration = $entry['configuration'];

            if (isset($configuration['attendance']['check_in_blocked']) && $configuration['attendance']['check_in_blocked'] === true) {
                return [
                    'allowed' => false,
                    'reason' => $entry['policy']->name ?? 'Attendance blocked by policy.',
                    'policies' => $policies,
                ];
            }
        }

        return [
            'allowed' => true,
            'reason' => null,
            'policies' => $policies,
        ];
    }

    /**
     * Evaluate whether the employee is allowed to check out based on active
     * policies.
     *
     * @return array{allowed: bool, reason: string|null, policies: array<int, array{policy: Policy, configuration: array<string, mixed>}>}
     */
    public function evaluateCheckOut(Employee $employee, CarbonImmutable $date): array
    {
        $policies = $this->activeForDate($employee, $date);

        foreach ($policies as $entry) {
            $configuration = $entry['configuration'];

            if (isset($configuration['attendance']['check_out_blocked']) && $configuration['attendance']['check_out_blocked'] === true) {
                return [
                    'allowed' => false,
                    'reason' => $entry['policy']->name ?? 'Check-out blocked by policy.',
                    'policies' => $policies,
                ];
            }
        }

        return [
            'allowed' => true,
            'reason' => null,
            'policies' => $policies,
        ];
    }
}
