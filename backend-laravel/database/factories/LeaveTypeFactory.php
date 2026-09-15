<?php

namespace Database\Factories;

use App\Enums\RecordStatus;
use App\Models\LeaveType;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<LeaveType>
 */
class LeaveTypeFactory extends Factory
{
    protected $model = LeaveType::class;

    public function definition(): array
    {
        return [
            'code' => fake()->unique()->bothify('LT-#####'),
            'name' => fake()->word(),
            'category' => fake()->word(),
            'deducts_annual_balance' => fake()->boolean(),
            'description' => fake()->sentence(),
            'status' => RecordStatus::Active->value,
        ];
    }

    public function annual(): static
    {
        return $this->state(fn (array $attributes): array => [
            'code' => 'annual_leave',
            'name' => 'Annual Leave',
            'category' => 'annual',
            'deducts_annual_balance' => true,
            'status' => RecordStatus::Active->value,
        ]);
    }

    public function special(): static
    {
        return $this->state(fn (array $attributes): array => [
            'category' => 'special',
            'deducts_annual_balance' => false,
            'status' => RecordStatus::Active->value,
        ]);
    }
}
