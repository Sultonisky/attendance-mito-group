<?php

namespace Database\Factories;

use App\Enums\WorkAreaType;
use App\Models\EmployeeWorkLocation;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<EmployeeWorkLocation>
 */
class EmployeeWorkLocationFactory extends Factory
{
    protected $model = EmployeeWorkLocation::class;

    public function definition(): array
    {
        return [
            'code' => fake()->unique()->bothify('EWL-#####'),
            'name' => fake()->company(),
            'city' => fake()->city(),
            'area_type' => WorkAreaType::Branch->value,
            'address' => fake()->address(),
            'latitude' => fake()->latitude(-8, -5),
            'longitude' => fake()->longitude(105, 113),
            'radius_meters' => 150,
            'status' => 'active',
        ];
    }

    public function areaType(WorkAreaType $type): static
    {
        return $this->state(fn (array $attributes) => [
            'area_type' => $type->value,
        ]);
    }

    public function atCoordinates(float $latitude, float $longitude, float $radiusMeters = 150): static
    {
        return $this->state(fn (array $attributes) => [
            'latitude' => $latitude,
            'longitude' => $longitude,
            'radius_meters' => $radiusMeters,
        ])->afterCreating(fn (EmployeeWorkLocation $location) => $location->syncLocationPoint());
    }

    public function inactive(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'inactive',
        ]);
    }
}
