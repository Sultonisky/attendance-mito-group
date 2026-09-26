<?php

namespace Database\Factories;

use App\Enums\AttendanceCorrectionStatus;
use App\Enums\AttendanceCorrectionType;
use App\Models\AttendanceCorrectionRequest;
use App\Models\Employee;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AttendanceCorrectionRequest>
 */
class AttendanceCorrectionRequestFactory extends Factory
{
    protected $model = AttendanceCorrectionRequest::class;

    public function definition(): array
    {
        $date = now()->subDay()->toDateString();

        return [
            'employee_id' => Employee::factory(),
            'attendance_record_id' => null,
            'attendance_session_id' => null,
            'request_type' => AttendanceCorrectionType::Both->value,
            'attendance_date' => $date,
            'requested_check_in_at' => $date.' 08:00:00',
            'requested_check_out_at' => $date.' 17:00:00',
            'reason' => 'Forgot to clock in and out.',
            'status' => AttendanceCorrectionStatus::Pending->value,
            'reviewed_by' => null,
            'reviewed_at' => null,
            'rejection_reason' => null,
        ];
    }

    public function forEmployee(Employee $employee): static
    {
        return $this->state(fn () => ['employee_id' => $employee->id]);
    }

    public function pending(): static
    {
        return $this->state(fn () => [
            'status' => AttendanceCorrectionStatus::Pending->value,
        ]);
    }
}
