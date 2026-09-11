<?php

namespace Database\Factories;

use App\Enums\PolicyStatus;
use App\Models\Policy;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Policy>
 */
class PolicyFactory extends Factory
{
    protected $model = Policy::class;

    public function definition(): array
    {
        return [
            'code' => fake()->unique()->bothify('POL-#####'),
            'name' => fake()->word(),
            'description' => fake()->sentence(),
            'status' => PolicyStatus::Active->value,
            'effective_from' => now()->subYear()->toDateString(),
            'effective_to' => fake()->optional(20)->dateTimeBetween('+1 month', '+1 year')?->format('Y-m-d'),
            'configuration' => [],
        ];
    }

    public function draft(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => PolicyStatus::Draft->value,
        ]);
    }

    public function expired(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => PolicyStatus::Expired->value,
            'effective_to' => fake()->dateTimeBetween('-1 year', '-1 day')->format('Y-m-d'),
        ]);
    }

    public function openEnded(): static
    {
        return $this->state(fn (array $attributes) => [
            'effective_to' => null,
        ]);
    }
}
