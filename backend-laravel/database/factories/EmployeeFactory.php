<?php

namespace Database\Factories;

use App\Enums\EmploymentStatus;
use App\Models\Employee;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Employee>
 */
class EmployeeFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'employee_code' => fake()->unique()->numerify('EMP#####'),
            'full_name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'phone' => fake()->phoneNumber(),
            'employment_status' => EmploymentStatus::Permanent->value,
            'join_date' => now()->subYears(1)->toDateString(),
        ];
    }
}
