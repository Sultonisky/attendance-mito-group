<?php

namespace Database\Factories;

use App\Models\LeaveType;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<LeaveType>
 */
class LeaveTypeFactory extends Factory
{
    protected $model = LeaveType::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'code' => fake()->unique()->lexify('leave_????'),
            'name' => fake()->words(2, true),
            'category' => 'special',
            'deducts_annual_balance' => false,
            'description' => fake()->sentence(),
            'status' => 'active',
        ];
    }

    public function annual(): static
    {
        return $this->state(fn (array $attributes): array => [
            'code' => 'annual_leave',
            'name' => 'Annual Leave',
            'category' => 'annual',
            'deducts_annual_balance' => true,
            'status' => 'active',
        ]);
    }

    public function special(): static
    {
        return $this->state(fn (array $attributes): array => [
            'category' => 'special',
            'deducts_annual_balance' => false,
            'status' => 'active',
        ]);
    }
}
