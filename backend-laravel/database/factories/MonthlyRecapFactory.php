<?php

namespace Database\Factories;

use App\Models\Employee;
use App\Models\MonthlyRecap;
use Illuminate\Database\Eloquent\Factories\Factory;

class MonthlyRecapFactory extends Factory
{
    protected $model = MonthlyRecap::class;

    public function definition(): array
    {
        return [
            'employee_id' => Employee::factory(),
            'period' => now()->format('Y-m'),
            'status' => 'draft',
            'summary' => [],
        ];
    }
}
