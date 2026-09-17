<?php

namespace Database\Factories;

use App\Models\Outsource;
use App\Models\OutsourceStoreAssignment;
use App\Models\WorkLocation;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<OutsourceStoreAssignment>
 */
class OutsourceStoreAssignmentFactory extends Factory
{
    protected $model = OutsourceStoreAssignment::class;

    public function definition(): array
    {
        return [
            'status' => 'active',
        ];
    }

    public function forOutsource(Outsource $outsource): static
    {
        return $this->state(fn (array $attributes) => [
            'outsource_id' => $outsource->id,
        ]);
    }

    public function forStore(WorkLocation $store): static
    {
        return $this->state(fn (array $attributes) => [
            'store_id' => $store->id,
        ]);
    }

    public function inactive(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'inactive',
        ]);
    }
}
