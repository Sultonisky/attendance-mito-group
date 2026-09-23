<?php

namespace Database\Factories;

use App\Models\WorkLocation;
use App\Models\WorkLocationPin;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<WorkLocationPin>
 */
class WorkLocationPinFactory extends Factory
{
    protected $model = WorkLocationPin::class;

    public function definition(): array
    {
        return [
            'work_location_id' => WorkLocation::factory(),
            'name' => fake()->streetName(),
            'address' => fake()->address(),
            'latitude' => fake()->latitude(),
            'longitude' => fake()->longitude(),
            'radius_meters' => 150,
            'status' => 'active',
        ];
    }

    public function forLocation(WorkLocation $location): static
    {
        return $this->state(fn (array $attributes) => [
            'work_location_id' => $location->id,
            'latitude' => $location->latitude ?? fake()->latitude(),
            'longitude' => $location->longitude ?? fake()->longitude(),
            'radius_meters' => $location->radius_meters ?? 150,
            'name' => $location->name.' Pin',
        ]);
    }

    public function atCoordinates(float $lat, float $lng, float $radius = 150): static
    {
        return $this->state(fn (array $attributes) => [
            'latitude' => $lat,
            'longitude' => $lng,
            'radius_meters' => $radius,
        ]);
    }

    public function inactive(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'inactive',
        ]);
    }
}
