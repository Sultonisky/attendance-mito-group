<?php

namespace Database\Factories;

use App\Models\Policy;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Policy>
 */
class PolicyFactory extends Factory
{
    public function definition(): array
    {
        return [
            'code' => fake()->unique()->numerify('PLC#####'),
            'name' => fake()->words(3, true),
            'description' => fake()->sentence(),
            'status' => 'active',
            'effective_from' => now()->subMonth()->toDateString(),
            'effective_to' => null,
            'configuration' => [],
        ];
    }
}
