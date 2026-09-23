<?php

namespace Database\Factories;

use App\Models\Outsource;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Outsource>
 */
class OutsourceFactory extends Factory
{
    protected $model = Outsource::class;

    public function definition(): array
    {
        return [
            'outsource_code' => sprintf('%03d', fake()->unique()->numberBetween(1, 9999)),
            'name' => fake()->name(),
            'password' => Outsource::DEFAULT_LOGIN_PIN,
            'status' => 'active',
        ];
    }

    public function inactive(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'inactive',
        ]);
    }
}
