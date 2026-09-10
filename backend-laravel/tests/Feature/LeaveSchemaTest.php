<?php

namespace Tests\Feature;

use App\Enums\LeaveCategory;
use App\Models\Employee;
use App\Models\LeaveBalance;
use App\Models\LeaveRequest;
use App\Models\LeaveTransaction;
use App\Models\LeaveType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LeaveSchemaTest extends TestCase
{
    use RefreshDatabase;

    private function makeEmployee(): Employee
    {
        return Employee::factory()->create();
    }

    private function makeAnnualLeaveType(): LeaveType
    {
        return LeaveType::create([
            'code' => 'annual_leave',
            'name' => 'Annual Leave',
            'category' => LeaveCategory::Annual->value,
            'deducts_annual_balance' => true,
        ]);
    }

    /**
     * Leave types distinguish annual vs special leave.
     */
    public function test_leave_type_category_and_deduction_flag(): void
    {
        $annual = $this->makeAnnualLeaveType();
        $special = LeaveType::create([
            'code' => 'bereavement',
            'name' => 'Bereavement',
            'category' => LeaveCategory::Special->value,
            'deducts_annual_balance' => false,
        ]);

        $this->assertSame(LeaveCategory::Annual->value, $annual->fresh()->category);
        $this->assertTrue($annual->fresh()->deducts_annual_balance);
        $this->assertSame(LeaveCategory::Special->value, $special->fresh()->category);
        $this->assertFalse($special->fresh()->deducts_annual_balance);
    }

    /**
     * A leave request belongs to its employee and leave type.
     */
    public function test_leave_request_belongs_to_employee_and_type(): void
    {
        $employee = $this->makeEmployee();
        $type = $this->makeAnnualLeaveType();

        $request = LeaveRequest::create([
            'employee_id' => $employee->id,
            'leave_type_id' => $type->id,
            'status' => 'pending',
            'start_date' => '2026-10-01',
            'end_date' => '2026-10-03',
        ]);

        $this->assertSame($employee->id, $request->employee()->first()->id);
        $this->assertSame($type->id, $request->leaveType()->first()->id);
        $this->assertSame(1, $employee->leaveRequests()->count());
    }

    /**
     * A leave balance belongs to its employee and type.
     */
    public function test_leave_balance_belongs_to_employee_and_type(): void
    {
        $employee = $this->makeEmployee();
        $type = $this->makeAnnualLeaveType();

        $balance = LeaveBalance::create([
            'employee_id' => $employee->id,
            'leave_type_id' => $type->id,
            'period' => '2026',
            'balance' => 12,
            'expires_at' => '2026-12-31',
        ]);

        $this->assertSame($employee->id, $balance->employee()->first()->id);
        $this->assertSame($type->id, $balance->leaveType()->first()->id);
        $this->assertSame(1, $employee->leaveBalances()->count());
    }

    /**
     * The transaction ledger preserves history with balance_after snapshots.
     */
    public function test_leave_transaction_ledger_preserves_history(): void
    {
        $employee = $this->makeEmployee();
        $type = $this->makeAnnualLeaveType();
        $balance = LeaveBalance::create([
            'employee_id' => $employee->id,
            'leave_type_id' => $type->id,
            'period' => '2026',
            'balance' => 10,
        ]);

        LeaveTransaction::create([
            'employee_id' => $employee->id,
            'leave_balance_id' => $balance->id,
            'transaction_type' => 'accrual',
            'amount' => 10,
            'balance_after' => 10,
            'reason' => 'Annual accrual',
            'occurred_at' => '2026-01-01 00:00:00',
        ]);
        LeaveTransaction::create([
            'employee_id' => $employee->id,
            'leave_balance_id' => $balance->id,
            'transaction_type' => 'consumption',
            'amount' => -2,
            'balance_after' => 8,
            'reason' => 'Approved leave consumption',
            'occurred_at' => '2026-03-01 00:00:00',
        ]);

        $transactions = $balance->transactions()->orderBy('occurred_at')->get();

        $this->assertSame(2, $transactions->count());
        $this->assertSame('accrual', $transactions[0]->transaction_type);
        $this->assertSame(10.0, (float) $transactions[0]->balance_after);
        $this->assertSame('consumption', $transactions[1]->transaction_type);
        $this->assertSame(8.0, (float) $transactions[1]->balance_after);
        $this->assertNotNull($transactions[0]->created_at);
    }
}
