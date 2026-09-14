<?php

namespace Database\Factories;

use App\Models\WorkLocation;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<WorkLocation>
 */
class WorkLocationFactory extends Factory
{
    protected $model = WorkLocation::class;

    public function definition(): array
    {
        return [
            'code' => fake()->unique()->bothify('LOC-#####'),
            'name' => fake()->word(),
            'latitude' => fake()->latitude(),
            'longitude' => fake()->longitude(),
            'radius_meters' => fake()->randomFloat(2, 50, 500),
            'status' => 'active',
        ];
    }

    public function inactive(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'inactive',
        ]);
    }

    public function atCoordinates(float $lat, float $lng, float $radius = 100): static
    {
        return $this->state(fn (array $attributes) => [
            'latitude' => $lat,
            'longitude' => $lng,
            'radius_meters' => $radius,
        ]);
    }
}
