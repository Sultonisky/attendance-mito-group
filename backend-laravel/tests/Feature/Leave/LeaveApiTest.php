<?php

namespace Tests\Feature\Leave;

use App\Models\Employee;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LeaveApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        LeaveType::factory()->annual()->create();
    }

    private function userWithEmployee(string $role): array
    {
        $user = User::factory()->create();
        $user->assignRole($role);
        $employee = Employee::factory()->create(['join_date' => '2025-01-01', 'user_id' => $user->id, 'email' => $user->email]);

        return [$user, $employee];
    }

    public function test_leave_endpoints_require_auth_and_return_expected_shapes(): void
    {
        $this->getJson('/api/v1/leave/types')->assertUnauthorized();
        $this->getJson('/api/v1/leave/balance')->assertUnauthorized();

        [$user] = $this->userWithEmployee('USER');
        $this->actingAs($user, 'sanctum')->getJson('/api/v1/leave/types')->assertOk()->assertJsonPath('success', true);
        $balance = $this->actingAs($user, 'sanctum')->getJson('/api/v1/leave/balance')->assertOk();
        $balance->assertJsonStructure(['data' => ['employee_id', 'available', 'batches']]);

        $this->actingAs($user, 'sanctum')->getJson('/api/v1/leave/requests')->assertOk();
    }

    public function test_validation_failures_return_422(): void
    {
        [$user] = $this->userWithEmployee('USER');
        $this->actingAs($user, 'sanctum')->postJson('/api/v1/leave/requests', [
            'leave_type_id' => 999999, 'start_date' => '2026-09-12', 'end_date' => '2026-09-10',
        ])->assertStatus(422);
    }

    public function test_employee_cannot_see_other_employee_requests(): void
    {
        [$userA] = $this->userWithEmployee('USER');
        [$userB, $employeeB] = $this->userWithEmployee('USER');
        $annual = LeaveType::where('code', 'annual_leave')->first();
        $other = LeaveRequest::create([
            'employee_id' => $employeeB->id, 'leave_type_id' => $annual->id, 'status' => 'pending',
            'start_date' => '2026-09-10', 'end_date' => '2026-09-10',
        ]);

        $this->actingAs($userA, 'sanctum')->getJson("/api/v1/leave/requests/{$other->id}")->assertStatus(404);
        $list = $this->actingAs($userA, 'sanctum')->getJson('/api/v1/leave/requests')->assertOk();
        $this->assertSame([], $list->json('data'));
    }

    public function test_unauthorized_user_cannot_approve(): void
    {
        [$user, $employee] = $this->userWithEmployee('USER');
        $annual = LeaveType::where('code', 'annual_leave')->first();
        $leave = LeaveRequest::create([
            'employee_id' => $employee->id, 'leave_type_id' => $annual->id, 'status' => 'pending',
            'start_date' => '2026-09-10', 'end_date' => '2026-09-10',
        ]);

        // USER lacks leave.approve: route middleware returns 403.
        $this->actingAs($user, 'sanctum')->postJson("/api/v1/leave/requests/{$leave->id}/approve")->assertForbidden();
    }

    public function test_self_approval_is_blocked(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole('ADMIN');
        $admin->givePermissionTo('leave.approve');
        $employee = Employee::factory()->create(['join_date' => '2025-01-01', 'user_id' => $admin->id, 'email' => $admin->email]);
        $annual = LeaveType::where('code', 'annual_leave')->first();
        $leave = LeaveRequest::create([
            'employee_id' => $employee->id, 'leave_type_id' => $annual->id, 'status' => 'pending',
            'start_date' => '2026-09-10', 'end_date' => '2026-09-10',
        ]);

        $this->actingAs($admin, 'sanctum')->postJson("/api/v1/leave/requests/{$leave->id}/approve")->assertStatus(422);
    }
}
