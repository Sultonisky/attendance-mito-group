<?php

namespace Database\Factories;

use App\Enums\VerificationStatus;
use App\Enums\VerificationType;
use App\Models\AttendanceRecord;
use App\Models\AttendanceSession;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AttendanceVerification>
 */
class AttendanceVerificationFactory extends Factory
{
    protected $model = AttendanceVerification::class;

    public function definition(): array
    {
        return [
            'attendance_id' => AttendanceRecord::factory(),
            'attendance_session_id' => null,
            'employee_id' => AttendanceRecord::factory()->employeeId(),
            'verification_type' => VerificationType::Geofence->value,
            'status' => VerificationStatus::Passed->value,
            'verified_at' => now(),
            'details' => [
                'latitude' => fake()->latitude(),
                'longitude' => fake()->longitude(),
            ],
        ];
    }

    public function geofence(): static
    {
        return $this->state(fn (array $attributes) => [
            'verification_type' => VerificationType::Geofence->value,
        ]);
    }

    public function gps(): static
    {
        return $this->state(fn (array $attributes) => [
            'verification_type' => VerificationType::Gps->value,
        ]);
    }

    public function forRecord(AttendanceRecord $record): static
    {
        return $this->state(fn (array $attributes) => [
            'attendance_id' => $record->id,
            'employee_id' => $record->employee_id,
        ]);
    }

    public function forSession(AttendanceSession $session): static
    {
        return $this->state(fn (array $attributes) => [
            'attendance_session_id' => $session->id,
        ]);
    }
}
