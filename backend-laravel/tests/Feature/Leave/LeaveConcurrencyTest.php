<?php

namespace Tests\Feature\Leave;

use App\Actions\Audit\RecordAuditAction;
use App\Actions\Leave\ApproveLeaveRequest;
use App\Domain\Leave\Engines\LeaveEngine;
use App\Domain\Leave\Exceptions\InsufficientLeaveBalanceException;
use App\Models\AuditLog;
use App\Models\Employee;
use App\Models\LeaveBalance;
use App\Models\LeaveRequest;
use App\Models\LeaveTransaction;
use App\Models\LeaveType;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LeaveConcurrencyTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        LeaveType::factory()->annual()->create();
    }

    private function employeeFor(User $user, string $joinDate = '2025-01-01'): Employee
    {
        return Employee::factory()->create(['join_date' => $joinDate, 'user_id' => $user->id, 'email' => $user->email]);
    }

    private function approver(): User
    {
        $admin = User::factory()->create();
        $admin->assignRole('ADMIN');

        return $admin;
    }

    public function test_audit_failure_rolls_back_approval(): void
    {
        $user = User::factory()->create();
        $user->assignRole('USER');
        $employee = $this->employeeFor($user);
        $annual = LeaveType::where('code', 'annual_leave')->first();
        $leave = LeaveRequest::create([
            'employee_id' => $employee->id, 'leave_type_id' => $annual->id, 'status' => 'pending',
            'start_date' => '2026-09-10', 'end_date' => '2026-09-10',
        ]);
        $admin = $this->approver();

        $failing = \Mockery::mock(RecordAuditAction::class);
        $failing->shouldReceive('execute')->andThrow(new \RuntimeException('audit store down'));
        $this->app->instance(RecordAuditAction::class, $failing);

        try {
            app(ApproveLeaveRequest::class)->execute($leave, $admin);
            $this->fail('Approval should have thrown when audit fails.');
        } catch (\RuntimeException) {
            // expected
        }

        $this->assertSame('pending', $leave->fresh()->status);
        $this->assertFalse(LeaveTransaction::where('transaction_type', 'consumption')->exists());
        $this->assertSame(0, AuditLog::where('action', 'leave.request_approved')->count());
    }

    public function test_balance_never_goes_negative(): void
    {
        $user = User::factory()->create();
        $user->assignRole('USER');
        $employee = $this->employeeFor($user);
        $annual = LeaveType::where('code', 'annual_leave')->first();
        $leave = LeaveRequest::create([
            'employee_id' => $employee->id, 'leave_type_id' => $annual->id, 'status' => 'pending',
            'start_date' => '2026-09-10', 'end_date' => '2026-12-31',
        ]);

        try {
            app(ApproveLeaveRequest::class)->execute($leave, $this->approver());
            $this->fail('Approval with insufficient balance should fail.');
        } catch (InsufficientLeaveBalanceException) {
            // expected
        }

        $this->assertSame('pending', $leave->fresh()->status);
        foreach (LeaveBalance::where('employee_id', $employee->id)->get() as $row) {
            $this->assertGreaterThanOrEqual(0, (float) $row->balance);
        }
    }

    public function test_attendance_resolver_only_sees_approved_leave(): void
    {
        $user = User::factory()->create();
        $user->assignRole('USER');
        $employee = $this->employeeFor($user);
        $annual = LeaveType::where('code', 'annual_leave')->first();
        $engine = app(LeaveEngine::class);

        $pending = LeaveRequest::create([
            'employee_id' => $employee->id, 'leave_type_id' => $annual->id, 'status' => 'pending',
            'start_date' => '2026-09-10', 'end_date' => '2026-09-12',
        ]);
        $this->assertFalse($engine->resolveForDate($employee, CarbonImmutable::parse('2026-09-11'))->onApprovedLeave);

        $pending->update(['status' => 'rejected']);
        $this->assertFalse($engine->resolveForDate($employee, CarbonImmutable::parse('2026-09-11'))->onApprovedLeave);

        $pending->update(['status' => 'approved']);
        $resolution = $engine->resolveForDate($employee, CarbonImmutable::parse('2026-09-11'));
        $this->assertTrue($resolution->onApprovedLeave);
        $this->assertSame($pending->id, $resolution->leaveRequestId);
        $this->assertFalse($engine->resolveForDate($employee, CarbonImmutable::parse('2026-09-20'))->onApprovedLeave);
    }

    public function test_special_type_configured_to_deduct_consumes_annual(): void
    {
        $user = User::factory()->create();
        $user->assignRole('USER');
        $employee = $this->employeeFor($user);
        $special = LeaveType::factory()->create([
            'code' => 'unpaid_bridge', 'name' => 'Bridge', 'category' => 'special',
            'deducts_annual_balance' => true, 'status' => 'active',
        ]);
        $leave = LeaveRequest::create([
            'employee_id' => $employee->id, 'leave_type_id' => $special->id, 'status' => 'pending',
            'start_date' => '2026-09-10', 'end_date' => '2026-09-10',
        ]);

        app(ApproveLeaveRequest::class)->execute($leave, $this->approver());

        $this->assertSame('approved', $leave->fresh()->status);
        $this->assertTrue(LeaveTransaction::where('transaction_type', 'consumption')->exists());
    }
}
