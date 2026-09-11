<?php

namespace Database\Factories;

use App\Enums\AttendanceSessionStatus;
use App\Models\AttendanceRecord;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AttendanceSession>
 */
class AttendanceSessionFactory extends Factory
{
    protected $model = AttendanceSession::class;

    public function definition(): array
    {
        return [
            'attendance_record_id' => AttendanceRecord::factory(),
            'check_in_at' => now(),
            'check_out_at' => null,
            'duration_minutes' => null,
            'status' => AttendanceSessionStatus::Open->value,
        ];
    }

    public function closed(): static
    {
        return $this->state(fn (array $attributes) => [
            'check_out_at' => now()->addHours(8),
            'duration_minutes' => 480,
            'status' => AttendanceSessionStatus::Closed->value,
        ]);
    }

    public function forRecord(AttendanceRecord $record): static
    {
        return $this->state(fn (array $attributes) => [
            'attendance_record_id' => $record->id,
        ]);
    }
}
