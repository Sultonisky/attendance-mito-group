<?php

namespace Database\Factories;

use App\Models\Employee;
use App\Models\MonthlyRecap;
use App\Models\Outsource;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<MonthlyRecap>
 */
class MonthlyRecapFactory extends Factory
{
    protected $model = MonthlyRecap::class;

    public function definition(): array
    {
        return [
            'employee_id' => Employee::factory(),
            'outsource_id' => null,
            'source' => 'employee',
            'period' => now()->format('Y-m'),
            'status' => 'draft',
            'summary' => [],
        ];
    }

    public function forOutsource(?Outsource $outsource = null): static
    {
        return $this->state(fn () => [
            'employee_id' => null,
            'outsource_id' => $outsource?->id ?? Outsource::factory(),
            'source' => 'outsource',
        ]);
    }
}
