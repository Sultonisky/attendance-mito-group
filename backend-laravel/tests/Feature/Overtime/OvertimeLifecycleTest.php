<?php

namespace Tests\Feature\Overtime;

use App\Models\AttendanceRecord;
use App\Models\AttendanceSession;
use App\Models\Employee;
use App\Models\OvertimeRecord;
use App\Models\OvertimeRequest;
use App\Models\User;
use App\Models\WorkSchedule;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OvertimeLifecycleTest extends TestCase
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

    private function makeAttendanceWithOvertime(Employee $employee, string $date = '2026-09-10'): AttendanceRecord
    {
        $schedule = WorkSchedule::factory()->create();
        $schedule->shifts()->create([
            'work_schedule_id' => $schedule->id,
            'name' => 'Standard',
            'start_time' => '08:00:00',
            'end_time' => '17:00:00',
            'break_start' => null,
            'break_end' => null,
            'cross_midnight' => false,
        ]);
        $employee->scheduleAssignments()->create([
            'work_schedule_id' => $schedule->id,
            'effective_from' => '2026-01-01',
            'effective_to' => null,
        ]);

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

    public function test_request_approve_reject_cancel_lifecycle(): void
    {
        [$user, $employee] = $this->makeActiveUserAndEmployee('USER');
        $record = $this->makeAttendanceWithOvertime($employee);

        $admin = User::factory()->create();
        $admin->assignRole('ADMIN');
        $admin->givePermissionTo(['overtime.approve', 'overtime.reject', 'overtime.cancel']);

        $create = $this->actingAs($user, 'sanctum')->postJson('/api/v1/overtime/requests', [
            'attendance_id' => $record->id,
            'requested_minutes' => 120,
        ]);
        $create->assertStatus(201);
        $id = $create->json('data.id');

        $this->actingAs($admin, 'sanctum')->postJson("/api/v1/overtime/requests/{$id}/approve")
            ->assertOk()->assertJsonPath('data.status', 'approved');

        $this->actingAs($user, 'sanctum')->postJson("/api/v1/overtime/requests/{$id}/cancel")
            ->assertStatus(422);
    }

    public function test_duplicate_approval_is_state_safe(): void
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

        $this->actingAs($admin, 'sanctum')->postJson("/api/v1/overtime/requests/{$request->id}/approve")->assertOk();
        $this->actingAs($admin, 'sanctum')->postJson("/api/v1/overtime/requests/{$request->id}/approve")->assertOk();
    }

    public function test_duplicate_cancellation_is_state_safe(): void
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

        $this->actingAs($user, 'sanctum')->postJson("/api/v1/overtime/requests/{$request->id}/cancel")->assertOk();
        $this->actingAs($user, 'sanctum')->postJson("/api/v1/overtime/requests/{$request->id}/cancel")->assertOk();
    }
}
