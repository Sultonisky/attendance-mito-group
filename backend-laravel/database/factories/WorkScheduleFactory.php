<?php

namespace Database\Factories;

use App\Enums\RecordStatus;
use App\Models\WorkSchedule;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<WorkSchedule>
 */
class WorkScheduleFactory extends Factory
{
    protected $model = WorkSchedule::class;

    public function definition(): array
    {
        return [
            'code'           => fake()->unique()->bothify('SCH-#####'),
            'name'           => fake()->word(),
            'description'    => fake()->sentence(),
            'status'         => RecordStatus::Active->value,
            'effective_from' => now()->subYear()->toDateString(),
            'effective_to'   => fake()->optional(20)->dateTimeBetween('+1 month', '+1 year')?->format('Y-m-d'),
        ];
    }

    public function inactive(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => RecordStatus::Inactive->value,
        ]);
    }

    public function openEnded(): static
    {
        return $this->state(fn (array $attributes) => [
            'effective_to' => null,
        ]);
    }
}
