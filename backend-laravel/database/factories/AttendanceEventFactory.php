<?php

namespace Database\Factories;

use App\Enums\AttendanceEventType;
use App\Models\AttendanceRecord;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AttendanceEvent>
 */
class AttendanceEventFactory extends Factory
{
    protected $model = AttendanceEvent::class;

    public function definition(): array
    {
        return [
            'employee_id' => AttendanceRecord::factory()->employeeId(),
            'attendance_id' => AttendanceRecord::factory(),
            'attendance_session_id' => null,
            'event_type' => AttendanceEventType::CheckIn->value,
            'occurred_at' => now(),
            'latitude' => fake()->latitude(),
            'longitude' => fake()->longitude(),
            'accuracy_meters' => fake()->randomFloat(2, 1, 50),
            'source' => 'mobile',
            'device_metadata' => [],
        ];
    }

    public function checkIn(): static
    {
        return $this->state(fn (array $attributes) => [
            'event_type' => AttendanceEventType::CheckIn->value,
        ]);
    }

    public function checkOut(): static
    {
        return $this->state(fn (array $attributes) => [
            'event_type' => AttendanceEventType::CheckOut->value,
        ]);
    }

    public function forRecord(AttendanceRecord $record): static
    {
        return $this->state(fn (array $attributes) => [
            'attendance_id' => $record->id,
            'employee_id' => $record->employee_id,
        ]);
    }

    public function forSession($session): static
    {
        return $this->state(fn (array $attributes) => [
            'attendance_session_id' => $session->id,
        ]);
    }
}
