<?php

namespace Database\Factories;

use App\Enums\RecordStatus;
use App\Models\PenaltyRule;
use Illuminate\Database\Eloquent\Factories\Factory;

class PenaltyRuleFactory extends Factory
{
    protected $model = PenaltyRule::class;

    public function definition(): array
    {
        return [
            'code' => fake()->unique()->bothify('PEN-#####'),
            'name' => fake()->word(),
            'description' => fake()->sentence(),
            'points' => fake()->randomFloat(2, 1, 10),
            'frequency' => fake()->optional()->word(),
            'threshold' => fake()->optional()->numberBetween(1, 60),
            'configuration' => [],
            'status' => RecordStatus::Active->value,
        ];
    }

    public function inactive(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => RecordStatus::Inactive->value,
        ]);
    }

    public function forViolation(string $violationType, ?int $threshold = null, ?float $points = null): static
    {
        return $this->state(fn (array $attributes) => [
            'code' => strtolower(str_replace('_', '_', $violationType)),
            'name' => ucwords(strtolower(str_replace('_', ' ', $violationType))),
            'configuration' => array_filter([
                'violation_type' => $violationType,
            ]) + ($threshold !== null ? ['threshold' => $threshold] : []),
            'points' => $points ?? fake()->randomFloat(2, 1, 10),
            'threshold' => $threshold,
        ]);
    }
}
