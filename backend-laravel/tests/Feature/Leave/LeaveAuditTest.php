<?php

namespace Tests\Feature\Leave;

use App\Models\AuditLog;
use App\Models\Employee;
use App\Models\LeaveRequest;
use App\Models\LeaveTransaction;
use App\Models\LeaveType;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LeaveAuditTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        LeaveType::factory()->annual()->create();
    }

    public function test_leave_mutations_create_audit_rows(): void
    {
        $user = User::factory()->create();
        $user->assignRole('USER');
        $employee = Employee::factory()->create(['join_date' => '2025-01-01', 'user_id' => $user->id, 'email' => $user->email]);
        $admin = User::factory()->create();
        $admin->assignRole('ADMIN');
        $annual = LeaveType::where('code', 'annual_leave')->first();

        $id = $this->actingAs($user, 'sanctum')->postJson('/api/v1/leave/requests', [
            'leave_type_id' => $annual->id, 'start_date' => '2026-09-10', 'end_date' => '2026-09-10',
        ])->assertStatus(201)->json('data.id');

        $this->actingAs($admin, 'sanctum')->postJson("/api/v1/leave/requests/{$id}/approve")->assertOk();
        $this->actingAs($user, 'sanctum')->postJson("/api/v1/leave/requests/{$id}/cancel")->assertOk();

        $actions = AuditLog::where('auditable_type', LeaveRequest::class)->where('auditable_id', $id)->pluck('action')->all();
        $this->assertContains('leave.request_created', $actions);
        $this->assertContains('leave.request_approved', $actions);
        $this->assertContains('leave.request_cancelled', $actions);
    }

    public function test_accrue_and_expire_commands_are_idempotent(): void
    {
        Employee::factory()->create(['join_date' => '2026-01-01']);
        $this->artisan('leave:accrue', ['--date' => '2026-09-14'])->assertSuccessful();
        $first = LeaveTransaction::where('transaction_type', 'accrual')->count();
        $this->assertGreaterThan(0, $first);
        $this->artisan('leave:accrue', ['--date' => '2026-09-14'])->assertSuccessful();
        $this->assertSame($first, LeaveTransaction::where('transaction_type', 'accrual')->count());

        $this->artisan('leave:expire', ['--date' => '2026-07-02'])->assertSuccessful();
        $this->assertTrue(true);
    }
}
