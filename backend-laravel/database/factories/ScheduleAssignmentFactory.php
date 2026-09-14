<?php

namespace Database\Factories;

use App\Models\Employee;
use App\Models\ScheduleAssignment;
use App\Models\WorkSchedule;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ScheduleAssignment>
 */
class ScheduleAssignmentFactory extends Factory
{
    protected $model = ScheduleAssignment::class;

    public function definition(): array
    {
        return [
            'employee_id' => Employee::factory(),
            'work_schedule_id' => WorkSchedule::factory(),
            'effective_from' => now()->subYear()->toDateString(),
            'effective_to' => fake()->optional(20)->dateTimeBetween('+1 month', '+1 year')?->format('Y-m-d'),
        ];
    }

    public function forEmployee(Employee $employee): static
    {
        return $this->state(fn (array $attributes) => [
            'employee_id' => $employee->id,
        ]);
    }

    public function forSchedule(WorkSchedule $schedule): static
    {
        return $this->state(fn (array $attributes) => [
            'work_schedule_id' => $schedule->id,
        ]);
    }

    public function effectiveFrom(string $date): static
    {
        return $this->state(fn (array $attributes) => [
            'effective_from' => $date,
        ]);
    }

    public function effectiveTo(?string $date): static
    {
        return $this->state(fn (array $attributes) => [
            'effective_to' => $date,
        ]);
    }

    public function openEnded(): static
    {
        return $this->state(fn (array $attributes) => [
            'effective_to' => null,
        ]);
    }
}
