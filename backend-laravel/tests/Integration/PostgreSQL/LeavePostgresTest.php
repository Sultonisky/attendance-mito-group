<?php

namespace Tests\Integration\PostgreSQL;

use App\Actions\Leave\ApproveLeaveRequest;
use App\Actions\Leave\CancelLeaveRequest;
use App\Domain\Leave\Engines\LeaveEngine;
use App\Domain\Leave\Exceptions\InsufficientLeaveBalanceException;
use App\Enums\LeaveCategory;
use App\Models\AuditLog;
use App\Models\Employee;
use App\Models\LeaveBalance;
use App\Models\LeaveRequest;
use App\Models\LeaveTransaction;
use App\Models\LeaveType;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;
use Tests\Traits\RefreshDatabasePostgres;

/**
 * Leave Engine runtime verification against PostgreSQL.
 *
 * Uses the established RefreshDatabasePostgres trait (migrate:fresh once per
 * run against the isolated `attendance_mito_test` database, TRUNCATE between
 * tests) so PostGIS DDL remains safe. Verifies DB-sensitive Leave behavior:
 * schema, dates, unique constraints, NULL expiry ordering, transactions,
 * audit, and approval locking.
 */
class LeavePostgresTest extends TestCase
{
    use RefreshDatabasePostgres;

    protected ?int $annualTypeId = null;

    protected ?int $adminId = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->refreshDatabase();
        $this->seed(RolesAndPermissionsSeeder::class);

        $annual = LeaveType::create([
            'code' => 'annual_leave',
            'name' => 'Annual Leave',
            'category' => LeaveCategory::Annual->value,
            'deducts_annual_balance' => true,
            'status' => 'active',
        ]);
        $this->annualTypeId = $annual->id;

        $admin = User::factory()->create();
        $admin->assignRole('ADMIN');
        $this->adminId = $admin->id;
    }

    private function employee(string $joinDate = '2026-03-14'): Employee
    {
        return Employee::factory()->create(['join_date' => $joinDate]);
    }

    private function requestFor(Employee $employee, string $start, string $end): LeaveRequest
    {
        return LeaveRequest::create([
            'employee_id' => $employee->id,
            'leave_type_id' => $this->annualTypeId,
            'status' => 'pending',
            'start_date' => $start,
            'end_date' => $end,
        ]);
    }

    private function approver(): User
    {
        return User::whereKey($this->adminId)->firstOrFail();
    }

    public function test_approval_consumes_balance_and_audits_on_postgres(): void
    {
        $engine = app(LeaveEngine::class);
        $employee = $this->employee();
        $annual = LeaveType::whereKey($this->annualTypeId)->firstOrFail();
        LeaveBalance::create([
            'employee_id' => $employee->id, 'leave_type_id' => $annual->id,
            'period' => '2026-07-01', 'balance' => 1, 'expires_at' => '2027-07-01',
        ]);
        $leave = $this->requestFor($employee, '2026-09-10', '2026-09-11');

        app(ApproveLeaveRequest::class)->execute($leave, $this->approver());

        $this->assertSame('approved', $leave->fresh()->status);
        $this->assertSame(0.0, (float) LeaveBalance::where('employee_id', $employee->id)->where('leave_type_id', $annual->id)->where('period', '2026-07-01')->first()->balance);
        $this->assertSame(2, LeaveTransaction::where('employee_id', $employee->id)->where('transaction_type', 'consumption')->count());
        $this->assertSame(0.0, $engine->availableBalance($employee, $annual)->available);
        $this->assertSame(1, AuditLog::where('action', 'leave.request_approved')->count());
    }

    public function test_unique_constraint_prevents_duplicate_period_balance(): void
    {
        $employee = $this->employee();
        LeaveBalance::create([
            'employee_id' => $employee->id, 'leave_type_id' => $this->annualTypeId,
            'period' => '2026-07-01', 'balance' => 1, 'expires_at' => '2027-07-01',
        ]);

        $this->expectException(UniqueConstraintViolationException::class);
        LeaveBalance::create([
            'employee_id' => $employee->id, 'leave_type_id' => $this->annualTypeId,
            'period' => '2026-07-01', 'balance' => 1, 'expires_at' => '2027-07-01',
        ]);
    }

    public function test_fifo_null_expiry_ordering_on_postgres(): void
    {
        $employee = $this->employee();
        $annual = LeaveType::whereKey($this->annualTypeId)->firstOrFail();
        $olderId = LeaveBalance::create([
            'employee_id' => $employee->id, 'leave_type_id' => $annual->id,
            'period' => '2026-07-01', 'balance' => 1, 'expires_at' => '2027-07-01',
        ])->id;
        $newerId = LeaveBalance::create([
            'employee_id' => $employee->id, 'leave_type_id' => $annual->id,
            'period' => '2026-08-01', 'balance' => 1, 'expires_at' => '2027-08-01',
        ])->id;
        // Null expiry must sort after dated batches on PostgreSQL (NULLS LAST).
        $noExpiryId = LeaveBalance::create([
            'employee_id' => $employee->id, 'leave_type_id' => $annual->id,
            'period' => '2026-09-01', 'balance' => 1, 'expires_at' => null,
        ])->id;

        $leave = $this->requestFor($employee, '2026-10-01', '2026-10-01');
        app(ApproveLeaveRequest::class)->execute($leave, $this->approver());

        $consumption = LeaveTransaction::where('employee_id', $employee->id)
            ->where('transaction_type', 'consumption')->orderBy('id')->firstOrFail();
        $this->assertSame($olderId, $consumption->leave_balance_id, 'Oldest dated batch must be consumed first (FIFO), never NULL-expiry first.');

        $batches = app(LeaveEngine::class)->availableBalance($employee, $annual)->batches;
        $this->assertSame($newerId, (int) $batches[0]['id'], 'Dated batches must sort before NULL-expiry on PostgreSQL.');
        $this->assertSame($noExpiryId, (int) $batches[2]['id'], 'NULL-expiry must sort last on PostgreSQL.');
    }

    public function test_leave_request_lifecycle_persists_on_postgres(): void
    {
        $employee = $this->employee();
        $leave = $this->requestFor($employee, '2026-09-10', '2026-09-10');

        app(ApproveLeaveRequest::class)->execute($leave, $this->approver());
        $this->assertSame('approved', $leave->fresh()->status);

        $cancelled = app(CancelLeaveRequest::class)->execute($leave->fresh(), $this->approver());
        $this->assertSame('cancelled', $cancelled->fresh()->status);
        $this->assertTrue(LeaveTransaction::where('leave_request_id', $leave->id)->where('transaction_type', 'reversal')->exists());
        $this->assertSame(1, AuditLog::where('action', 'leave.request_cancelled')->count());
    }

    public function test_two_requests_for_one_day_cannot_double_consume_on_postgres(): void
    {
        // Sequential approvals under row-locking: production consume()
        // serializes via lockForUpdate in ApproveLeaveRequest txn.
        // Join 2026-03-14 eligible 2026-09-14 accrues only Sep = 1d plus
        // seeded 1d = 2 total. 2-day request drains seeded batch via FIFO.
        // A consumes 1 (1 left), B consumes 1 (0 left), C must fail.
        // Final invariant: consumption never exceeds balance.
        $employee = $this->employee();
        $annual = LeaveType::whereKey($this->annualTypeId)->firstOrFail();
        LeaveBalance::create([
            'employee_id' => $employee->id, 'leave_type_id' => $annual->id,
            'period' => '2099-01-01', 'balance' => 1, 'expires_at' => '2100-01-01',
        ]);

        $requestA = $this->requestFor($employee, '2026-10-01', '2026-10-01');
        $requestB = $this->requestFor($employee, '2026-10-02', '2026-10-02');
        $requestC = $this->requestFor($employee, '2026-10-05', '2026-10-07');

        app(ApproveLeaveRequest::class)->execute($requestA, $this->approver());
        app(ApproveLeaveRequest::class)->execute($requestB, $this->approver());
        $this->assertSame('approved', $requestA->fresh()->status);
        $this->assertSame('approved', $requestB->fresh()->status);

        $consumed = LeaveTransaction::where('employee_id', $employee->id)
            ->where('transaction_type', 'consumption')->count();
        $this->assertSame(2, $consumed, 'Two 1-day approvals consume one txn each.');
        $balanceAfter = (float) LeaveBalance::where('employee_id', $employee->id)->where('leave_type_id', $annual->id)->sum('balance');
        $this->assertSame(0.0, $balanceAfter, 'Balance must never become negative.');

        // C needs 3 days with 0 left: must fail, balance unchanged.
        $this->expectException(InsufficientLeaveBalanceException::class);
        app(ApproveLeaveRequest::class)->execute($requestC, $this->approver());
    }
}
