<?php

namespace Database\Factories;

use App\Enums\RecordStatus;
use App\Models\LeaveType;
use Illuminate\Database\Eloquent\Factories\Factory;

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
}
