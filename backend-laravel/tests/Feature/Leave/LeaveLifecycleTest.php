<?php

namespace Tests\Feature\Leave;

use App\Models\Employee;
use App\Models\LeaveBalance;
use App\Models\LeaveRequest;
use App\Models\LeaveTransaction;
use App\Models\LeaveType;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LeaveLifecycleTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        LeaveType::factory()->annual()->create();
        LeaveType::factory()->create(['code' => 'sick', 'name' => 'Sick', 'category' => 'special', 'deducts_annual_balance' => false]);
    }

    private function userWithEmployee(string $role, string $joinDate = '2025-01-01'): array
    {
        $user = User::factory()->create();
        $user->assignRole($role);
        $employee = Employee::factory()->create(['join_date' => $joinDate, 'user_id' => $user->id, 'email' => $user->email]);

        return [$user, $employee];
    }

    private function approver(): User
    {
        $user = User::factory()->create();
        $user->assignRole('ADMIN');

        return $user;
    }

    public function test_request_approve_reject_cancel_lifecycle(): void
    {
        [$user, $employee] = $this->userWithEmployee('USER');
        $annual = LeaveType::where('code', 'annual_leave')->first();
        $admin = $this->approver();

        $create = $this->actingAs($user, 'sanctum')->postJson('/api/v1/leave/requests', [
            'leave_type_id' => $annual->id, 'start_date' => '2026-09-10', 'end_date' => '2026-09-11',
        ]);
        $create->assertStatus(201);
        $id = $create->json('data.id');

        $approve = $this->actingAs($admin, 'sanctum')->postJson("/api/v1/leave/requests/{$id}/approve");
        $approve->assertOk()->assertJsonPath('data.status', 'approved');

        // Duplicate approval is state-safe.
        $this->actingAs($admin, 'sanctum')->postJson("/api/v1/leave/requests/{$id}/approve")->assertOk();

        $cancel = $this->actingAs($user, 'sanctum')->postJson("/api/v1/leave/requests/{$id}/cancel");
        $cancel->assertOk()->assertJsonPath('data.status', 'cancelled');

        // Duplicate cancellation is state-safe.
        $this->actingAs($user, 'sanctum')->postJson("/api/v1/leave/requests/{$id}/cancel")->assertOk();

        $this->assertTrue(LeaveTransaction::where('transaction_type', 'consumption')->exists());
        $this->assertTrue(LeaveTransaction::where('transaction_type', 'reversal')->exists());
    }

    public function test_rejected_request_does_not_consume_balance(): void
    {
        [$user] = $this->userWithEmployee('USER');
        $annual = LeaveType::where('code', 'annual_leave')->first();
        $admin = $this->approver();

        $id = $this->actingAs($user, 'sanctum')->postJson('/api/v1/leave/requests', [
            'leave_type_id' => $annual->id, 'start_date' => '2026-09-10', 'end_date' => '2026-09-10',
        ])->json('data.id');

        $this->actingAs($admin, 'sanctum')->postJson("/api/v1/leave/requests/{$id}/reject", ['reason' => 'No cover'])
            ->assertOk()->assertJsonPath('data.status', 'rejected');

        $this->assertFalse(LeaveTransaction::where('transaction_type', 'consumption')->exists());
    }

    public function test_special_leave_does_not_reduce_annual_balance(): void
    {
        [$user, $employee] = $this->userWithEmployee('USER');
        $sick = LeaveType::where('code', 'sick')->first();
        $admin = $this->approver();

        $id = $this->actingAs($user, 'sanctum')->postJson('/api/v1/leave/requests', [
            'leave_type_id' => $sick->id, 'start_date' => '2026-09-10', 'end_date' => '2026-09-11',
        ])->json('data.id');

        $this->actingAs($admin, 'sanctum')->postJson("/api/v1/leave/requests/{$id}/approve")->assertOk();
        $this->assertFalse(LeaveTransaction::where('transaction_type', 'consumption')->exists());
        $this->assertSame(0, LeaveBalance::where('employee_id', $employee->id)->count());
    }

    public function test_overlap_is_rejected(): void
    {
        [$user] = $this->userWithEmployee('USER');
        $annual = LeaveType::where('code', 'annual_leave')->first();
        $admin = $this->approver();

        $first = $this->actingAs($user, 'sanctum')->postJson('/api/v1/leave/requests', [
            'leave_type_id' => $annual->id, 'start_date' => '2026-09-10', 'end_date' => '2026-09-12',
        ])->json('data.id');
        $this->actingAs($admin, 'sanctum')->postJson("/api/v1/leave/requests/{$first}/approve")->assertOk();

        $this->actingAs($user, 'sanctum')->postJson('/api/v1/leave/requests', [
            'leave_type_id' => $annual->id, 'start_date' => '2026-09-11', 'end_date' => '2026-09-13',
        ])->assertStatus(422);
    }

    public function test_insufficient_balance_blocks_approval(): void
    {
        [$user] = $this->userWithEmployee('USER', '2026-08-01');
        $annual = LeaveType::where('code', 'annual_leave')->first();
        $admin = $this->approver();

        // Eligibility itself blocks approval for a 1-month employee.
        $id = LeaveRequest::create([
            'employee_id' => $user->employee->id, 'leave_type_id' => $annual->id, 'status' => 'pending',
            'start_date' => '2026-09-10', 'end_date' => '2026-09-12',
        ])->id;

        $this->actingAs($admin, 'sanctum')->postJson("/api/v1/leave/requests/{$id}/approve")->assertStatus(422);
        $this->assertSame('pending', LeaveRequest::find($id)->status);
    }
}
