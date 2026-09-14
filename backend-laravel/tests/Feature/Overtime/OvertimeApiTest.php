<?php

namespace Tests\Feature\Overtime;

use App\Models\AttendanceRecord;
use App\Models\AttendanceSession;
use App\Models\Employee;
use App\Models\OvertimeRecord;
use App\Models\OvertimeRequest;
use App\Models\Policy;
use App\Models\PolicyAssignment;
use App\Models\ScheduleAssignment;
use App\Models\Shift;
use App\Models\User;
use App\Models\WorkSchedule;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OvertimeApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    private function makeUser(string $role = 'USER', array $overrides = []): User
    {
        $user = User::factory()->create($overrides);
        $user->assignRole($role);

        return $user;
    }

    private function makeEmployee(array $overrides = []): Employee
    {
        return Employee::factory()->create($overrides);
    }

    private function makeActiveUserAndEmployee(string $role = 'USER'): array
    {
        $user = $this->makeUser($role);
        $employee = $this->makeEmployee();
        $employee->update(['user_id' => $user->id]);

        return [$user, $employee];
    }

    private function makeScheduleAndPolicy(Employee $employee): void
    {
        $schedule = WorkSchedule::factory()->create();
        Shift::factory()->forSchedule($schedule)->create([
            'start_time' => '08:00:00',
            'end_time' => '17:00:00',
        ]);
        ScheduleAssignment::factory()->forEmployee($employee)->forSchedule($schedule)->create([
            'effective_from' => '2026-01-01',
            'effective_to' => null,
        ]);

        $policy = Policy::factory()->create();
        PolicyAssignment::factory()->forEmployee($employee)->forPolicy($policy)->create([
            'effective_from' => '2026-01-01',
            'effective_to' => null,
        ]);
    }

    private function makeAttendanceWithOvertime(Employee $employee, string $date = '2026-09-10'): AttendanceRecord
    {
        $this->makeScheduleAndPolicy($employee);
        $record = AttendanceRecord::factory()->forEmployee($employee)->onDate($date)->status('present')->create();
        AttendanceSession::create([
            'attendance_record_id' => $record->id,
            'check_in_at' => '2026-09-10 08:00:00',
            'check_out_at' => '2026-09-10 19:00:00',
            'duration_minutes' => 660,
            'status' => 'closed',
        ]);

        return $record;
    }

    public function test_overtime_endpoints_require_auth(): void
    {
        $this->getJson('/api/v1/overtime')->assertUnauthorized();
        $this->getJson('/api/v1/overtime/requests')->assertUnauthorized();
    }

    public function test_user_can_view_own_overtime_records(): void
    {
        [$user, $employee] = $this->makeActiveUserAndEmployee();
        $record = $this->makeAttendanceWithOvertime($employee);

        OvertimeRecord::create([
            'employee_id' => $employee->id,
            'attendance_id' => $record->id,
            'date' => '2026-09-10',
            'potential_minutes' => 120,
            'requested_minutes' => 0,
            'approved_minutes' => null,
            'actual_minutes' => null,
            'status' => 'potential',
        ]);

        $response = $this->actingAs($user, 'sanctum')->getJson('/api/v1/overtime');
        $response->assertOk();
    }

    public function test_user_can_request_overtime(): void
    {
        [$user, $employee] = $this->makeActiveUserAndEmployee('USER');
        $record = $this->makeAttendanceWithOvertime($employee);

        $response = $this->actingAs($user, 'sanctum')->postJson('/api/v1/overtime/requests', [
            'attendance_id' => $record->id,
            'requested_minutes' => 120,
            'reason' => 'Project deadline',
        ]);

        $response->assertStatus(201);
        $response->assertJsonPath('success', true);
        $this->assertSame(120, $response->json('data.requested_minutes'));
    }

    public function test_validation_failure_returns_422(): void
    {
        [$user, $employee] = $this->makeActiveUserAndEmployee('USER');

        $this->actingAs($user, 'sanctum')->postJson('/api/v1/overtime/requests', [
            'attendance_id' => 999999,
            'requested_minutes' => -5,
        ])->assertStatus(422);
    }

    public function test_employee_cannot_view_other_employee_overtime(): void
    {
        [$userA, $employeeA] = $this->makeActiveUserAndEmployee();
        [$userB, $employeeB] = $this->makeActiveUserAndEmployee();

        $recordA = $this->makeAttendanceWithOvertime($employeeA);
        $recordB = $this->makeAttendanceWithOvertime($employeeB);

        $overtimeA = OvertimeRecord::create([
            'employee_id' => $employeeA->id,
            'attendance_id' => $recordA->id,
            'date' => '2026-09-10',
            'potential_minutes' => 120,
            'status' => 'potential',
        ]);

        $this->actingAs($userB, 'sanctum')->getJson("/api/v1/overtime/{$overtimeA->id}")->assertStatus(404);
    }

    public function test_unauthorized_user_cannot_approve(): void
    {
        [$user, $employee] = $this->makeActiveUserAndEmployee();
        $record = $this->makeAttendanceWithOvertime($employee);

        $request = OvertimeRequest::create([
            'employee_id' => $employee->id,
            'attendance_id' => $record->id,
            'date' => '2026-09-10',
            'requested_minutes' => 120,
            'status' => 'pending',
        ]);

        OvertimeRecord::create([
            'employee_id' => $employee->id,
            'attendance_id' => $record->id,
            'overtime_request_id' => $request->id,
            'date' => '2026-09-10',
            'potential_minutes' => 120,
            'requested_minutes' => 0,
            'approved_minutes' => null,
            'actual_minutes' => null,
            'status' => 'potential',
        ]);

        $this->actingAs($user, 'sanctum')->postJson("/api/v1/overtime/requests/{$request->id}/approve")->assertForbidden();
    }

    public function test_self_approval_is_blocked(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole('ADMIN');
        $admin->givePermissionTo('overtime.approve');
        $employee = Employee::factory()->create(['user_id' => $admin->id, 'email' => $admin->email]);
        $record = $this->makeAttendanceWithOvertime($employee);

        $request = OvertimeRequest::create([
            'employee_id' => $employee->id,
            'attendance_id' => $record->id,
            'date' => '2026-09-10',
            'requested_minutes' => 120,
            'status' => 'pending',
        ]);

        OvertimeRecord::create([
            'employee_id' => $employee->id,
            'attendance_id' => $record->id,
            'overtime_request_id' => $request->id,
            'date' => '2026-09-10',
            'potential_minutes' => 120,
            'requested_minutes' => 0,
            'approved_minutes' => null,
            'actual_minutes' => null,
            'status' => 'potential',
        ]);

        $this->actingAs($admin, 'sanctum')->postJson("/api/v1/overtime/requests/{$request->id}/approve")->assertStatus(422);
    }

    public function test_admin_can_approve_overtime_request(): void
    {
        [$user, $employee] = $this->makeActiveUserAndEmployee('USER');
        $record = $this->makeAttendanceWithOvertime($employee);

        $request = OvertimeRequest::create([
            'employee_id' => $employee->id,
            'attendance_id' => $record->id,
            'date' => '2026-09-10',
            'requested_minutes' => 120,
            'status' => 'pending',
        ]);

        OvertimeRecord::create([
            'employee_id' => $employee->id,
            'attendance_id' => $record->id,
            'overtime_request_id' => $request->id,
            'date' => '2026-09-10',
            'potential_minutes' => 120,
            'requested_minutes' => 0,
            'approved_minutes' => null,
            'actual_minutes' => null,
            'status' => 'potential',
        ]);

        $admin = User::factory()->create();
        $admin->assignRole('ADMIN');
        $admin->givePermissionTo('overtime.approve');

        $this->actingAs($admin, 'sanctum')->postJson("/api/v1/overtime/requests/{$request->id}/approve")
            ->assertOk()
            ->assertJsonPath('data.status', 'approved');

        $this->assertSame('approved', $request->fresh()->status);
        $this->assertSame(120, $request->fresh()->approved_minutes);
    }

    public function test_requested_minutes_equal_to_potential_is_allowed(): void
    {
        [$user, $employee] = $this->makeActiveUserAndEmployee('USER');
        $record = $this->makeAttendanceWithOvertime($employee);

        $response = $this->actingAs($user, 'sanctum')->postJson('/api/v1/overtime/requests', [
            'attendance_id' => $record->id,
            'requested_minutes' => 120,
        ]);

        $response->assertStatus(201);
        $this->assertSame(120, $response->json('data.requested_minutes'));
    }

    public function test_requested_minutes_less_than_potential_is_allowed(): void
    {
        [$user, $employee] = $this->makeActiveUserAndEmployee('USER');
        $record = $this->makeAttendanceWithOvertime($employee);

        $response = $this->actingAs($user, 'sanctum')->postJson('/api/v1/overtime/requests', [
            'attendance_id' => $record->id,
            'requested_minutes' => 60,
        ]);

        $response->assertStatus(201);
        $this->assertSame(60, $response->json('data.requested_minutes'));
    }

    public function test_requested_minutes_exceeding_potential_is_rejected(): void
    {
        [$user, $employee] = $this->makeActiveUserAndEmployee('USER');
        $record = $this->makeAttendanceWithOvertime($employee);

        $this->actingAs($user, 'sanctum')->postJson('/api/v1/overtime/requests', [
            'attendance_id' => $record->id,
            'requested_minutes' => 9999,
        ])->assertStatus(422);
    }

    public function test_approve_minutes_equal_to_potential_is_allowed(): void
    {
        [$user, $employee] = $this->makeActiveUserAndEmployee('USER');
        $record = $this->makeAttendanceWithOvertime($employee);

        $request = OvertimeRequest::create([
            'employee_id' => $employee->id,
            'attendance_id' => $record->id,
            'date' => '2026-09-10',
            'requested_minutes' => 120,
            'status' => 'pending',
        ]);

        OvertimeRecord::create([
            'employee_id' => $employee->id,
            'attendance_id' => $record->id,
            'overtime_request_id' => $request->id,
            'date' => '2026-09-10',
            'potential_minutes' => 120,
            'requested_minutes' => 0,
            'approved_minutes' => null,
            'actual_minutes' => null,
            'status' => 'potential',
        ]);

        $admin = User::factory()->create();
        $admin->assignRole('ADMIN');
        $admin->givePermissionTo('overtime.approve');

        $this->actingAs($admin, 'sanctum')->postJson("/api/v1/overtime/requests/{$request->id}/approve?approved_minutes=120")
            ->assertOk()
            ->assertJsonPath('data.status', 'approved');

        $this->assertSame(120, $request->fresh()->approved_minutes);
    }

    public function test_approve_minutes_less_than_potential_is_allowed(): void
    {
        [$user, $employee] = $this->makeActiveUserAndEmployee('USER');
        $record = $this->makeAttendanceWithOvertime($employee);

        $request = OvertimeRequest::create([
            'employee_id' => $employee->id,
            'attendance_id' => $record->id,
            'date' => '2026-09-10',
            'requested_minutes' => 120,
            'status' => 'pending',
        ]);

        OvertimeRecord::create([
            'employee_id' => $employee->id,
            'attendance_id' => $record->id,
            'overtime_request_id' => $request->id,
            'date' => '2026-09-10',
            'potential_minutes' => 120,
            'requested_minutes' => 0,
            'approved_minutes' => null,
            'actual_minutes' => null,
            'status' => 'potential',
        ]);

        $admin = User::factory()->create();
        $admin->assignRole('ADMIN');
        $admin->givePermissionTo('overtime.approve');

        $this->actingAs($admin, 'sanctum')->postJson("/api/v1/overtime/requests/{$request->id}/approve?approved_minutes=60")
            ->assertOk()
            ->assertJsonPath('data.status', 'approved');

        $this->assertSame(60, $request->fresh()->approved_minutes);
    }

    public function test_approve_minutes_exceeding_potential_is_rejected(): void
    {
        [$user, $employee] = $this->makeActiveUserAndEmployee('USER');
        $record = $this->makeAttendanceWithOvertime($employee);

        $request = OvertimeRequest::create([
            'employee_id' => $employee->id,
            'attendance_id' => $record->id,
            'date' => '2026-09-10',
            'requested_minutes' => 120,
            'status' => 'pending',
        ]);

        OvertimeRecord::create([
            'employee_id' => $employee->id,
            'attendance_id' => $record->id,
            'overtime_request_id' => $request->id,
            'date' => '2026-09-10',
            'potential_minutes' => 120,
            'requested_minutes' => 0,
            'approved_minutes' => null,
            'actual_minutes' => null,
            'status' => 'potential',
        ]);

        $admin = User::factory()->create();
        $admin->assignRole('ADMIN');
        $admin->givePermissionTo('overtime.approve');

        $this->actingAs($admin, 'sanctum')->postJson("/api/v1/overtime/requests/{$request->id}/approve?approved_minutes=9999")
            ->assertStatus(422);
    }
}
