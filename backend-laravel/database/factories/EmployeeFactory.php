<?php

namespace Database\Factories;

use App\Enums\EmploymentStatus;
use App\Models\Employee;
use App\Models\EmployeeWorkLocation;
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

    /**
     * Assign an active employee work location usable as check-in geofence.
     */
    public function withWorkLocation(float $latitude = -6.2, float $longitude = 106.8, float $radiusMeters = 1000): static
    {
        return $this->afterCreating(function (Employee $employee) use ($latitude, $longitude, $radiusMeters): void {
            $location = EmployeeWorkLocation::factory()
                ->atCoordinates($latitude, $longitude, $radiusMeters)
                ->create();

            $employee->workLocations()->attach($location->id, ['status' => 'active']);
        });
    }
}
