<?php

namespace Database\Factories;

use App\Enums\ApprovalStatus;
use App\Models\Employee;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use Illuminate\Database\Eloquent\Factories\Factory;

class LeaveRequestFactory extends Factory
{
    protected $model = LeaveRequest::class;

    public function definition(): array
    {
        return [
            'employee_id' => Employee::factory(),
            'leave_type_id' => LeaveType::factory(),
            'status' => ApprovalStatus::Pending->value,
            'start_date' => now()->toDateString(),
            'end_date' => now()->toDateString(),
            'reason' => fake()->sentence(),
        ];
    }

    public function approved(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => ApprovalStatus::Approved->value,
        ]);
    }

    public function rejected(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => ApprovalStatus::Rejected->value,
        ]);
    }

    public function cancelled(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => ApprovalStatus::Cancelled->value,
        ]);
    }

    public function forEmployee(Employee $employee): static
    {
        return $this->state(fn (array $attributes) => [
            'employee_id' => $employee->id,
        ]);
    }

    public function onDate(string $date): static
    {
        return $this->state(fn (array $attributes) => [
            'start_date' => $date,
            'end_date' => $date,
        ]);
    }
}
