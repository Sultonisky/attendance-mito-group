<?php

namespace Tests\Feature\Attendance;

use App\Models\AttendanceEvent;
use App\Models\AttendanceRecord;
use App\Models\AttendanceSession;
use App\Models\Employee;
use App\Models\Outsource;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EmployeeAttendanceAdminCrudTest extends TestCase
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

    private function adminWithCrud(): User
    {
        $user = $this->makeUser('USER');
        $user->givePermissionTo([
            'attendance.view',
            'attendance.create',
            'attendance.update',
            'attendance.void',
        ]);

        return $user;
    }

    public function test_create_requires_auth(): void
    {
        $this->postJson('/api/v1/attendance', [])
            ->assertUnauthorized();
    }

    public function test_create_requires_permission(): void
    {
        $user = $this->makeUser('USER');
        $user->givePermissionTo('attendance.view');

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/attendance', [])
            ->assertForbidden();
    }

    public function test_admin_can_create_present_record(): void
    {
        $admin = $this->adminWithCrud();
        $employee = Employee::factory()->create();

        $response = $this->actingAs($admin, 'sanctum')
            ->postJson('/api/v1/attendance', [
                'employee_id' => $employee->id,
                'attendance_date' => '2026-09-10',
                'check_in_at' => '2026-09-10 08:30',
                'check_out_at' => '2026-09-10 17:00',
            ]);

        $response->assertCreated()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.status', 'present')
            ->assertJsonPath('data.duration_minutes', 510)
            ->assertJsonPath('data.employee_id', $employee->id);

        $this->assertDatabaseHas('attendance_records', [
            'employee_id' => $employee->id,
            'status' => 'present',
            'attendable_type' => 'employee',
        ]);
        $this->assertDatabaseHas('attendance_events', [
            'employee_id' => $employee->id,
            'event_type' => 'check_in',
            'source' => 'admin',
        ]);
        $this->assertDatabaseHas('attendance_events', [
            'employee_id' => $employee->id,
            'event_type' => 'check_out',
            'source' => 'admin',
        ]);
    }

    public function test_admin_can_create_incomplete_record(): void
    {
        $admin = $this->adminWithCrud();
        $employee = Employee::factory()->create();

        $this->actingAs($admin, 'sanctum')
            ->postJson('/api/v1/attendance', [
                'employee_id' => $employee->id,
                'attendance_date' => '2026-09-11',
                'check_in_at' => '2026-09-11 08:30',
                'check_out_at' => null,
            ])
            ->assertCreated()
            ->assertJsonPath('data.status', 'incomplete')
            ->assertJsonPath('data.check_out_at', null);
    }

    public function test_admin_can_create_overnight_record(): void
    {
        $admin = $this->adminWithCrud();
        $employee = Employee::factory()->create();

        $this->actingAs($admin, 'sanctum')
            ->postJson('/api/v1/attendance', [
                'employee_id' => $employee->id,
                'attendance_date' => '2026-09-12',
                'check_in_at' => '2026-09-12 21:00',
                'check_out_at' => '2026-09-13 07:00',
            ])
            ->assertCreated()
            ->assertJsonPath('data.status', 'present')
            ->assertJsonPath('data.duration_minutes', 600)
            ->assertJsonPath('data.check_in_at', '2026-09-12T21:00:00+07:00')
            ->assertJsonPath('data.check_out_at', '2026-09-13T07:00:00+07:00');
    }

    public function test_create_rejects_duplicate_date(): void
    {
        $admin = $this->adminWithCrud();
        $employee = Employee::factory()->create();

        $payload = [
            'employee_id' => $employee->id,
            'attendance_date' => '2026-09-14',
            'check_in_at' => '2026-09-14 08:00',
            'check_out_at' => '2026-09-14 17:00',
        ];

        $this->actingAs($admin, 'sanctum')
            ->postJson('/api/v1/attendance', $payload)
            ->assertCreated();

        $this->actingAs($admin, 'sanctum')
            ->postJson('/api/v1/attendance', $payload)
            ->assertStatus(422)
            ->assertJsonPath('message', 'Attendance already exists for this employee on that date.');
    }

    public function test_admin_can_update_times(): void
    {
        $admin = $this->adminWithCrud();
        $employee = Employee::factory()->create();

        $create = $this->actingAs($admin, 'sanctum')
            ->postJson('/api/v1/attendance', [
                'employee_id' => $employee->id,
                'attendance_date' => '2026-09-16',
                'check_in_at' => '2026-09-16 08:00',
            ])
            ->assertCreated();

        $id = (int) $create->json('data.attendance_id');

        $this->actingAs($admin, 'sanctum')
            ->putJson('/api/v1/attendance/'.$id, [
                'check_in_at' => '2026-09-16 09:00',
                'check_out_at' => '2026-09-16 18:00',
            ])
            ->assertOk()
            ->assertJsonPath('data.status', 'present')
            ->assertJsonPath('data.duration_minutes', 540)
            ->assertJsonPath('data.check_in_at', '2026-09-16T09:00:00+07:00');

        $this->assertSame(1, AttendanceSession::query()->where('attendance_record_id', $id)->count());
        $this->assertSame(2, AttendanceEvent::query()->where('attendance_id', $id)->count());
    }

    public function test_update_rejects_non_employee_record(): void
    {
        $admin = $this->adminWithCrud();
        $outsource = Outsource::factory()->create(['status' => 'active']);

        $record = AttendanceRecord::create([
            'outsource_id' => $outsource->id,
            'attendable_type' => 'outsource',
            'attendance_date' => '2026-09-17',
            'status' => 'present',
        ]);

        $this->actingAs($admin, 'sanctum')
            ->putJson('/api/v1/attendance/'.$record->id, [
                'check_in_at' => '2026-09-17 08:00',
                'check_out_at' => '2026-09-17 17:00',
            ])
            ->assertStatus(422)
            ->assertJsonPath('message', 'Not an employee attendance record.');
    }

    public function test_admin_can_void_record(): void
    {
        $admin = $this->adminWithCrud();
        $employee = Employee::factory()->create();

        $create = $this->actingAs($admin, 'sanctum')
            ->postJson('/api/v1/attendance', [
                'employee_id' => $employee->id,
                'attendance_date' => '2026-09-18',
                'check_in_at' => '2026-09-18 08:30',
                'check_out_at' => '2026-09-18 17:00',
            ])
            ->assertCreated();

        $id = (int) $create->json('data.attendance_id');

        $this->actingAs($admin, 'sanctum')
            ->deleteJson('/api/v1/attendance/'.$id.'/void')
            ->assertOk()
            ->assertJsonPath('success', true);

        $this->assertDatabaseMissing('attendance_records', ['id' => $id]);
        $this->assertSame(0, AttendanceEvent::query()->where('attendance_id', $id)->count());
    }

    public function test_report_includes_check_in_out_for_edit(): void
    {
        $admin = $this->adminWithCrud();
        $admin->givePermissionTo('employees.view');
        $employee = Employee::factory()->create();

        $this->actingAs($admin, 'sanctum')
            ->postJson('/api/v1/attendance', [
                'employee_id' => $employee->id,
                'attendance_date' => '2026-09-19',
                'check_in_at' => '2026-09-19 08:30',
                'check_out_at' => '2026-09-19 17:00',
            ])
            ->assertCreated();

        $this->actingAs($admin, 'sanctum')
            ->getJson('/api/v1/reports/attendance?from=2026-09-01&to=2026-09-30')
            ->assertOk()
            ->assertJsonPath('data.0.check_in_at', '2026-09-19T08:30:00+07:00')
            ->assertJsonPath('data.0.check_out_at', '2026-09-19T17:00:00+07:00')
            ->assertJsonPath('data.0.duration_minutes', 510);
    }
}
