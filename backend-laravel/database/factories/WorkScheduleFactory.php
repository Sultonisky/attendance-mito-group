<?php

namespace Database\Factories;

use App\Models\WorkSchedule;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<WorkSchedule>
 */
class WorkScheduleFactory extends Factory
{
    public function definition(): array
    {
        return [
            'code' => fake()->unique()->numerify('SCHD#####'),
            'name' => fake()->words(3, true),
            'description' => fake()->sentence(),
            'status' => 'active',
            'effective_from' => now()->subMonth()->toDateString(),
            'effective_to' => null,
        ];
    }
}
