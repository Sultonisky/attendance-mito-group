<?php

namespace Database\Factories;

use App\Models\Employee;
use App\Models\LeaveBalance;
use App\Models\LeaveTransaction;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<LeaveTransaction>
 */
class LeaveTransactionFactory extends Factory
{
    protected $model = LeaveTransaction::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'employee_id' => Employee::factory(),
            'leave_balance_id' => LeaveBalance::factory(),
            'leave_request_id' => null,
            'transaction_type' => 'accrual',
            'amount' => 1,
            'balance_after' => 1,
            'reason' => fake()->sentence(),
            'occurred_at' => now(),
            'metadata' => [],
        ];
    }
}
