<?php

namespace Tests\Unit\Policies;

use App\Models\AttendanceRecord;
use App\Models\Employee;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class AttendanceRecordPolicyTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    private function userWithRole(string $roleName, array $attributes = []): User
    {
        $user = User::factory()->create($attributes);
        $user->assignRole($roleName);

        return $user;
    }

    private function makeEmployee(array $overrides = []): Employee
    {
        return Employee::factory()->create($overrides);
    }

    public function test_super_admin_can_view_any_attendance_record(): void
    {
        $superAdmin = $this->userWithRole('SUPER_ADMIN');
        $employee = $this->makeEmployee();
        $record = AttendanceRecord::create([
            'employee_id' => $employee->id,
            'attendance_date' => '2026-09-10',
            'status' => 'present',
        ]);

        $this->assertTrue($superAdmin->can('view', $record));
    }

    public function test_admin_with_attendance_view_can_view_any_attendance_record(): void
    {
        $admin = $this->userWithRole('ADMIN');
        $employee = $this->makeEmployee();
        $record = AttendanceRecord::create([
            'employee_id' => $employee->id,
            'attendance_date' => '2026-09-10',
            'status' => 'present',
        ]);

        $this->assertTrue($admin->can('view', $record));
    }

    public function test_user_can_view_own_attendance_record(): void
    {
        $user = $this->userWithRole('USER');
        $employee = $this->makeEmployee(['user_id' => $user->id]);
        $record = AttendanceRecord::create([
            'employee_id' => $employee->id,
            'attendance_date' => '2026-09-10',
            'status' => 'present',
        ]);

        $this->assertTrue($user->can('view', $record));
    }

    public function test_user_cannot_view_another_employee_attendance_record(): void
    {
        $userA = $this->userWithRole('USER');
        $userB = $this->userWithRole('USER');
        $employeeB = $this->makeEmployee(['user_id' => $userB->id]);
        $record = AttendanceRecord::create([
            'employee_id' => $employeeB->id,
            'attendance_date' => '2026-09-10',
            'status' => 'present',
        ]);

        // Remove attendance.view to test employee-scoped access
        $role = Role::findByName('USER');
        $role->syncPermissions([
            'dashboard.view',
            'penalty.view',
            'leave.view',
            'leave.create',
            'leave.cancel',
            'overtime.view',
            'overtime.create',
            'overtime.cancel',
            'monthly_recap.view',
        ]);

        $this->assertFalse($userA->can('view', $record));
    }

    public function test_user_without_attendance_view_can_view_own_record(): void
    {
        $user = $this->userWithRole('USER');
        // Remove attendance.view from USER role for this test
        $role = Role::findByName('USER');
        $role->syncPermissions([
            'dashboard.view',
            'penalty.view',
            'leave.view',
            'leave.create',
            'leave.cancel',
            'overtime.view',
            'overtime.create',
            'overtime.cancel',
            'monthly_recap.view',
        ]);

        $employee = $this->makeEmployee(['user_id' => $user->id]);
        $record = AttendanceRecord::create([
            'employee_id' => $employee->id,
            'attendance_date' => '2026-09-10',
            'status' => 'present',
        ]);

        $this->assertTrue($user->can('view', $record));
    }
}
