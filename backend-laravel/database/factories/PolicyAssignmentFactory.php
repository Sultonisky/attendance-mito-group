<?php

namespace Database\Factories;

use App\Models\Employee;
use App\Models\Policy;
use App\Models\PolicyAssignment;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PolicyAssignment>
 */
class PolicyAssignmentFactory extends Factory
{
    protected $model = PolicyAssignment::class;

    public function definition(): array
    {
        return [
            'employee_id' => Employee::factory(),
            'policy_id' => Policy::factory(),
            'effective_from' => now()->subYear()->toDateString(),
            'effective_to' => fake()->optional(20)->dateTimeBetween('+1 month', '+1 year')?->format('Y-m-d'),
        ];
    }

    public function forEmployee(Employee $employee): static
    {
        return $this->state(fn (array $attributes) => [
            'employee_id' => $employee->id,
        ]);
    }

    public function forPolicy(Policy $policy): static
    {
        return $this->state(fn (array $attributes) => [
            'policy_id' => $policy->id,
        ]);
    }

    public function effectiveFrom(string $date): static
    {
        return $this->state(fn (array $attributes) => [
            'effective_from' => $date,
        ]);
    }

    public function effectiveTo(?string $date): static
    {
        return $this->state(fn (array $attributes) => [
            'effective_to' => $date,
        ]);
    }

    public function openEnded(): static
    {
        return $this->state(fn (array $attributes) => [
            'effective_to' => null,
        ]);
    }
}
