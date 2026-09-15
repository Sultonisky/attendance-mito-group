<?php

namespace Database\Factories;

use App\Enums\ApprovalStatus;
use App\Enums\PermissionRequestType;
use App\Models\Employee;
use App\Models\PermissionRequest;
use Illuminate\Database\Eloquent\Factories\Factory;

class PermissionRequestFactory extends Factory
{
    protected $model = PermissionRequest::class;

    public function definition(): array
    {
        return [
            'employee_id' => Employee::factory(),
            'permission_type' => PermissionRequestType::Personal->value,
            'status' => ApprovalStatus::Pending->value,
            'start_at' => now(),
            'end_at' => now()->addHours(2),
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

    public function business(): static
    {
        return $this->state(fn (array $attributes) => [
            'permission_type' => PermissionRequestType::Business->value,
        ]);
    }

    public function forEmployee(Employee $employee): static
    {
        return $this->state(fn (array $attributes) => [
            'employee_id' => $employee->id,
        ]);
    }
}
