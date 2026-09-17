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
            'outsource_code' => fake()->unique()->numerify('OUT-#####'),
            'name' => fake()->name(),
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
