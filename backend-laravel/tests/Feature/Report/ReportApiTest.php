<?php

namespace Tests\Feature\Report;

use App\Models\AttendanceEvent;
use App\Models\AttendanceRecord;
use App\Models\AttendanceSession;
use App\Models\Employee;
use App\Models\EmployeeWorkLocation;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Models\OvertimeRecord;
use App\Models\PenaltyRecord;
use App\Models\PenaltyRule;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class ReportApiTest extends TestCase
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

    // ========================
    // Attendance Report
    // ========================

    public function test_attendance_report_requires_auth(): void
    {
        $this->getJson('/api/v1/reports/attendance?from=2026-09-01&to=2026-09-30')
            ->assertUnauthorized();
    }

    public function test_attendance_report_requires_permission(): void
    {
        $user = User::factory()->create();
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
        $user->assignRole('USER');

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/reports/attendance?from=2026-09-01&to=2026-09-30')
            ->assertForbidden();
    }

    public function test_user_sees_own_attendance_report(): void
    {
        [$user, $employee] = $this->makeActiveUserAndEmployee();

        AttendanceRecord::create([
            'employee_id' => $employee->id,
            'attendance_date' => '2026-09-10',
            'status' => 'present',
        ]);
        AttendanceRecord::create([
            'employee_id' => $employee->id,
            'attendance_date' => '2026-09-11',
            'status' => 'late',
        ]);

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/reports/attendance?from=2026-09-01&to=2026-09-30')
            ->assertOk()
            ->assertJsonCount(2, 'data');
    }

    public function test_attendance_report_respects_date_range(): void
    {
        [$user, $employee] = $this->makeActiveUserAndEmployee();

        AttendanceRecord::create([
            'employee_id' => $employee->id,
            'attendance_date' => '2026-09-10',
            'status' => 'present',
        ]);
        AttendanceRecord::create([
            'employee_id' => $employee->id,
            'attendance_date' => '2026-10-05',
            'status' => 'present',
        ]);

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/reports/attendance?from=2026-09-01&to=2026-09-30')
            ->assertOk()
            ->assertJsonCount(1, 'data');
    }

    public function test_attendance_report_employee_scoping(): void
    {
        [$userA, $employeeA] = $this->makeActiveUserAndEmployee();
        [$userB, $employeeB] = $this->makeActiveUserAndEmployee();

        AttendanceRecord::create([
            'employee_id' => $employeeA->id,
            'attendance_date' => '2026-09-10',
            'status' => 'present',
        ]);
        AttendanceRecord::create([
            'employee_id' => $employeeB->id,
            'attendance_date' => '2026-09-10',
            'status' => 'present',
        ]);

        $this->actingAs($userA, 'sanctum')
            ->getJson('/api/v1/reports/attendance?from=2026-09-01&to=2026-09-30')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.employee_id', $employeeA->id);
    }

    public function test_attendance_report_admin_sees_all_with_filter(): void
    {
        [$userA, $employeeA] = $this->makeActiveUserAndEmployee();
        [$userB, $employeeB] = $this->makeActiveUserAndEmployee();

        AttendanceRecord::create([
            'employee_id' => $employeeA->id,
            'attendance_date' => '2026-09-10',
            'status' => 'present',
        ]);
        AttendanceRecord::create([
            'employee_id' => $employeeB->id,
            'attendance_date' => '2026-09-10',
            'status' => 'present',
        ]);

        $admin = $this->makeUser('ADMIN');
        Employee::factory()->create(['user_id' => $admin->id]);

        $this->actingAs($admin, 'sanctum')
            ->getJson('/api/v1/reports/attendance?from=2026-09-01&to=2026-09-30')
            ->assertOk()
            ->assertJsonCount(2, 'data');
    }

    public function test_attendance_report_rejects_invalid_date_range(): void
    {
        [$user, $employee] = $this->makeActiveUserAndEmployee();

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/reports/attendance?from=2026-09-30&to=2026-09-01')
            ->assertStatus(422);
    }

    public function test_attendance_report_rejects_invalid_per_page(): void
    {
        [$user, $employee] = $this->makeActiveUserAndEmployee();

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/reports/attendance?from=2026-09-01&to=2026-09-30&per_page=0')
            ->assertStatus(422);
    }

    public function test_attendance_report_super_admin_sees_all(): void
    {
        [$userA, $employeeA] = $this->makeActiveUserAndEmployee();
        [$userB, $employeeB] = $this->makeActiveUserAndEmployee();

        AttendanceRecord::create([
            'employee_id' => $employeeA->id,
            'attendance_date' => '2026-09-10',
            'status' => 'present',
        ]);
        AttendanceRecord::create([
            'employee_id' => $employeeB->id,
            'attendance_date' => '2026-09-10',
            'status' => 'present',
        ]);

        $superAdmin = $this->makeUser('SUPER_ADMIN');

        $this->actingAs($superAdmin, 'sanctum')
            ->getJson('/api/v1/reports/attendance?from=2026-09-01&to=2026-09-30')
            ->assertOk()
            ->assertJsonCount(2, 'data');
    }

    // ========================
    // Leave Report
    // ========================

    public function test_leave_report_requires_auth(): void
    {
        $this->getJson('/api/v1/reports/leave?from=2026-09-01&to=2026-09-30')
            ->assertUnauthorized();
    }

    public function test_leave_report_requires_permission(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/reports/leave?from=2026-09-01&to=2026-09-30')
            ->assertForbidden();
    }

    public function test_user_with_leave_view_sees_own_leave(): void
    {
        [$user, $employee] = $this->makeActiveUserAndEmployee();
        $user->givePermissionTo('leave.view');

        $type = LeaveType::factory()->create(['code' => 'ANNUAL', 'name' => 'Annual Leave']);

        LeaveRequest::create([
            'employee_id' => $employee->id,
            'leave_type_id' => $type->id,
            'start_date' => '2026-09-10',
            'end_date' => '2026-09-12',
            'status' => 'approved',
            'reason' => 'Vacation',
        ]);

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/reports/leave?from=2026-09-01&to=2026-09-30')
            ->assertOk()
            ->assertJsonCount(1, 'data');
    }

    // ========================
    // Overtime Report
    // ========================

    public function test_overtime_report_requires_auth(): void
    {
        $this->getJson('/api/v1/reports/overtime?from=2026-09-01&to=2026-09-30')
            ->assertUnauthorized();
    }

    public function test_overtime_report_requires_permission(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/reports/overtime?from=2026-09-01&to=2026-09-30')
            ->assertForbidden();
    }

    public function test_user_with_overtime_view_sees_own_overtime(): void
    {
        [$user, $employee] = $this->makeActiveUserAndEmployee();
        $user->givePermissionTo('overtime.view');

        OvertimeRecord::create([
            'employee_id' => $employee->id,
            'date' => '2026-09-10',
            'potential_minutes' => 120,
            'requested_minutes' => 120,
            'approved_minutes' => 120,
            'actual_minutes' => 120,
            'status' => 'approved',
        ]);

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/reports/overtime?from=2026-09-01&to=2026-09-30')
            ->assertOk()
            ->assertJsonCount(1, 'data');
    }

    // ========================
    // Penalty Report
    // ========================

    public function test_penalty_report_requires_auth(): void
    {
        $this->getJson('/api/v1/reports/penalties?from=2026-09-01&to=2026-09-30')
            ->assertUnauthorized();
    }

    public function test_penalty_report_requires_permission(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/reports/penalties?from=2026-09-01&to=2026-09-30')
            ->assertForbidden();
    }

    public function test_user_with_penalty_view_sees_own_penalties(): void
    {
        [$user, $employee] = $this->makeActiveUserAndEmployee();
        $user->givePermissionTo('penalty.view');

        $rule = PenaltyRule::create([
            'code' => 'LATE',
            'name' => 'Late Arrival',
            'description' => 'Late arrival penalty',
            'points' => 10,
            'frequency' => 'per_occurrence',
            'threshold' => null,
            'configuration' => null,
            'status' => 'active',
        ]);

        PenaltyRecord::create([
            'employee_id' => $employee->id,
            'penalty_rule_id' => $rule->id,
            'source' => 'system',
            'violation_type' => 'late',
            'original_points' => 10,
            'adjusted_points' => 10,
            'final_points' => 10,
            'reason' => 'Late arrival',
            'status' => 'applied',
            'occurred_at' => '2026-09-10 08:30:00',
        ]);

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/reports/penalties?from=2026-09-01&to=2026-09-30')
            ->assertOk()
            ->assertJsonCount(1, 'data');
    }

    // ========================
    // Pagination
    // ========================

    public function test_attendance_report_pagination(): void
    {
        [$user, $employee] = $this->makeActiveUserAndEmployee();

        for ($i = 1; $i <= 5; $i++) {
            AttendanceRecord::create([
                'employee_id' => $employee->id,
                'attendance_date' => '2026-09-0'.$i,
                'status' => 'present',
            ]);
        }

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/reports/attendance?from=2026-09-01&to=2026-09-30&per_page=2')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('meta.total', 5)
            ->assertJsonPath('meta.per_page', 2);
    }

    // ========================
    // Empty Results
    // ========================

    public function test_attendance_report_returns_empty_for_valid_range(): void
    {
        [$user, $employee] = $this->makeActiveUserAndEmployee();

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/reports/attendance?from=2026-09-01&to=2026-09-30')
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }

    // ========================
    // Sorting
    // ========================

    public function test_attendance_report_sort_by_date(): void
    {
        [$user, $employee] = $this->makeActiveUserAndEmployee();

        AttendanceRecord::create([
            'employee_id' => $employee->id,
            'attendance_date' => '2026-09-15',
            'status' => 'present',
        ]);
        AttendanceRecord::create([
            'employee_id' => $employee->id,
            'attendance_date' => '2026-09-05',
            'status' => 'present',
        ]);

        $response = $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/reports/attendance?from=2026-09-01&to=2026-09-30&sort=attendance_date&direction=asc')
            ->assertOk();

        $this->assertSame('2026-09-05', $response->json('data.0.attendance_date'));
        $this->assertSame('2026-09-15', $response->json('data.1.attendance_date'));
    }

    public function test_attendance_report_includes_clock_locations_and_filters_search_and_status(): void
    {
        [$user, $employee] = $this->makeActiveUserAndEmployee();
        $employee->update([
            'employee_code' => '2022031601',
            'full_name' => 'Attendance Example',
        ]);
        $location = EmployeeWorkLocation::factory()->create([
            'name' => 'Jakarta Head Office',
            'city' => 'Jakarta',
        ]);
        $record = AttendanceRecord::create([
            'employee_id' => $employee->id,
            'attendance_date' => '2026-09-10',
            'status' => 'present',
        ]);
        $session = AttendanceSession::factory()->closed()->create([
            'attendance_record_id' => $record->id,
            'check_in_at' => '2026-09-10 08:00:00',
            'check_out_at' => '2026-09-10 17:00:00',
        ]);
        AttendanceEvent::factory()->checkIn()->forRecord($record)->forSession($session)->create([
            'employee_work_location_id' => $location->id,
            'occurred_at' => '2026-09-10 08:00:00',
            'latitude' => -6.2,
            'longitude' => 106.8,
            'accuracy_meters' => 8.5,
        ]);
        AttendanceEvent::factory()->checkOut()->forRecord($record)->forSession($session)->create([
            'employee_work_location_id' => $location->id,
            'occurred_at' => '2026-09-10 17:00:00',
            'latitude' => -6.21,
            'longitude' => 106.81,
            'accuracy_meters' => 10,
        ]);

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/reports/attendance?from=2026-09-01&to=2026-09-30&search=2022031601&status=present')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.employee_id', $employee->id)
            ->assertJsonPath('data.0.employee_code', '2022031601')
            ->assertJsonPath('data.0.check_in_location.work_location.id', $location->id)
            ->assertJsonPath('data.0.check_in_location.work_location.name', 'Jakarta Head Office')
            ->assertJsonPath('data.0.check_in_location.work_location.city', 'Jakarta')
            ->assertJsonPath('data.0.check_in_location.gps.latitude', -6.2)
            ->assertJsonPath('data.0.check_in_location.gps.accuracy_meters', 8.5)
            ->assertJsonPath('data.0.check_out_location.gps.longitude', 106.81);

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/reports/attendance?from=2026-09-01&to=2026-09-30&status=absent')
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }
}
