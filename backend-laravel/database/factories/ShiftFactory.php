<?php

namespace Database\Factories;

use App\Models\Shift;
use App\Models\WorkSchedule;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Shift>
 */
class ShiftFactory extends Factory
{
    protected $model = Shift::class;

    public function definition(): array
    {
        return [
            'work_schedule_id' => WorkSchedule::factory(),
            'name' => fake()->word(),
            'start_time' => '09:00:00',
            'end_time' => '18:00:00',
            'break_start' => '12:00:00',
            'break_end' => '13:00:00',
            'cross_midnight' => false,
        ];
    }

    public function forSchedule(WorkSchedule $schedule): static
    {
        return $this->state(fn (array $attributes) => [
            'work_schedule_id' => $schedule->id,
        ]);
    }

    public function crossMidnight(): static
    {
        return $this->state(fn (array $attributes) => [
            'start_time' => '22:00:00',
            'end_time' => '06:00:00',
            'cross_midnight' => true,
        ]);
    }
}
