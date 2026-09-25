<?php

namespace Tests\Feature\Dashboard;

use App\Models\AttendanceRecord;
use App\Models\Employee;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Models\Policy;
use App\Models\PolicyAssignment;
use App\Models\ScheduleAssignment;
use App\Models\Shift;
use App\Models\User;
use App\Models\WorkSchedule;
use Carbon\CarbonImmutable;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class DashboardKpiTest extends TestCase
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

    private function freezeToday(string $date): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::parse($date.' 00:00:00'));
    }

    private function clearTestNow(): void
    {
        CarbonImmutable::setTestNow();
    }

    protected function tearDown(): void
    {
        $this->clearTestNow();
        parent::tearDown();
    }

    // ========================
    // Authentication & Authorization
    // ========================

    public function test_dashboard_kpis_requires_auth(): void
    {
        $this->freezeToday('2026-09-15');
        $this->getJson('/api/v1/dashboard/kpis')->assertUnauthorized();
    }

    public function test_user_with_dashboard_view_sees_kpis(): void
    {
        $this->freezeToday('2026-09-15');
        [$user, $employee] = $this->makeActiveUserAndEmployee();
        $this->makeScheduleAndPolicy($employee);

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/dashboard/kpis')
            ->assertOk()
            ->assertJsonPath('data.date', '2026-09-15');
    }

    public function test_user_without_dashboard_view_is_forbidden(): void
    {
        $this->freezeToday('2026-09-15');
        $user = User::factory()->create();
        $role = Role::findByName('USER');
        $role->syncPermissions(array_filter($role->permissions->pluck('name')->all(), fn ($p) => $p !== 'dashboard.view'));
        $user->assignRole('USER');

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/dashboard/kpis')
            ->assertForbidden();
    }

    public function test_super_admin_sees_company_kpis(): void
    {
        $this->freezeToday('2026-09-15');
        [$userA, $employeeA] = $this->makeActiveUserAndEmployee();
        [$userB, $employeeB] = $this->makeActiveUserAndEmployee();
        $this->makeScheduleAndPolicy($employeeA);
        $this->makeScheduleAndPolicy($employeeB);

        $superAdmin = $this->makeUser('SUPER_ADMIN');

        $this->actingAs($superAdmin, 'sanctum')
            ->getJson('/api/v1/dashboard/kpis')
            ->assertOk()
            ->assertJsonPath('data.date', '2026-09-15');
    }

    // ========================
    // Population
    // ========================

    public function test_past_end_date_employee_excluded(): void
    {
        $this->freezeToday('2026-09-15');
        [$user, $employee] = $this->makeActiveUserAndEmployee();
        $employee->update(['end_date' => '2026-09-14']);
        $this->makeScheduleAndPolicy($employee);

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/dashboard/kpis')
            ->assertOk()
            ->assertJsonPath('data.present', 0)
            ->assertJsonPath('data.absent', 0)
            ->assertJsonPath('data.late', 0)
            ->assertJsonPath('data.on_leave', 0);
    }

    public function test_future_end_date_employee_included(): void
    {
        $this->freezeToday('2026-09-15');
        [$user, $employee] = $this->makeActiveUserAndEmployee();
        $employee->update(['end_date' => '2026-12-31']);
        $this->makeScheduleAndPolicy($employee);

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/dashboard/kpis')
            ->assertOk()
            ->assertJsonPath('data.absent', 1);
    }

    public function test_null_end_date_employee_included(): void
    {
        $this->freezeToday('2026-09-15');
        [$user, $employee] = $this->makeActiveUserAndEmployee();
        $employee->update(['end_date' => null]);
        $this->makeScheduleAndPolicy($employee);

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/dashboard/kpis')
            ->assertOk()
            ->assertJsonPath('data.absent', 1);
    }

    public function test_employee_without_schedule_excluded(): void
    {
        $this->freezeToday('2026-09-15');
        [$user, $employee] = $this->makeActiveUserAndEmployee();
        // No schedule created

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/dashboard/kpis')
            ->assertOk()
            ->assertJsonPath('data.present', 0)
            ->assertJsonPath('data.absent', 0)
            ->assertJsonPath('data.late', 0)
            ->assertJsonPath('data.on_leave', 0);
    }

    // ========================
    // Attendance
    // ========================

    public function test_scheduled_employee_with_attendance_is_present(): void
    {
        $this->freezeToday('2026-09-15');
        [$user, $employee] = $this->makeActiveUserAndEmployee();
        $this->makeScheduleAndPolicy($employee);

        AttendanceRecord::create([
            'employee_id' => $employee->id,
            'attendance_date' => '2026-09-15',
            'status' => 'present',
        ]);

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/dashboard/kpis')
            ->assertOk()
            ->assertJsonPath('data.present', 1)
            ->assertJsonPath('data.absent', 0)
            ->assertJsonPath('data.late', 0)
            ->assertJsonPath('data.on_leave', 0);
    }

    public function test_scheduled_employee_without_attendance_is_absent(): void
    {
        $this->freezeToday('2026-09-15');
        [$user, $employee] = $this->makeActiveUserAndEmployee();
        $this->makeScheduleAndPolicy($employee);

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/dashboard/kpis')
            ->assertOk()
            ->assertJsonPath('data.absent', 1)
            ->assertJsonPath('data.present', 0)
            ->assertJsonPath('data.late', 0)
            ->assertJsonPath('data.on_leave', 0);
    }

    public function test_late_employee_classified_as_late(): void
    {
        $this->freezeToday('2026-09-15');
        [$user, $employee] = $this->makeActiveUserAndEmployee();
        $this->makeScheduleAndPolicy($employee);

        AttendanceRecord::create([
            'employee_id' => $employee->id,
            'attendance_date' => '2026-09-15',
            'status' => 'late',
        ]);

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/dashboard/kpis')
            ->assertOk()
            ->assertJsonPath('data.late', 1)
            ->assertJsonPath('data.present', 0)
            ->assertJsonPath('data.absent', 0)
            ->assertJsonPath('data.on_leave', 0);
    }

    public function test_incomplete_attendance_classified_as_present(): void
    {
        $this->freezeToday('2026-09-15');
        [$user, $employee] = $this->makeActiveUserAndEmployee();
        $this->makeScheduleAndPolicy($employee);

        AttendanceRecord::create([
            'employee_id' => $employee->id,
            'attendance_date' => '2026-09-15',
            'status' => 'incomplete',
        ]);

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/dashboard/kpis')
            ->assertOk()
            ->assertJsonPath('data.present', 1)
            ->assertJsonPath('data.absent', 0)
            ->assertJsonPath('data.late', 0)
            ->assertJsonPath('data.on_leave', 0);
    }

    // ========================
    // Leave
    // ========================

    public function test_approved_leave_counts_as_on_leave(): void
    {
        $this->freezeToday('2026-09-15');
        [$user, $employee] = $this->makeActiveUserAndEmployee();
        $this->makeScheduleAndPolicy($employee);

        $type = LeaveType::factory()->create(['code' => 'ANNUAL', 'name' => 'Annual Leave']);

        LeaveRequest::create([
            'employee_id' => $employee->id,
            'leave_type_id' => $type->id,
            'start_date' => '2026-09-15',
            'end_date' => '2026-09-15',
            'status' => 'approved',
            'reason' => 'Vacation',
        ]);

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/dashboard/kpis')
            ->assertOk()
            ->assertJsonPath('data.on_leave', 1)
            ->assertJsonPath('data.absent', 0)
            ->assertJsonPath('data.present', 0)
            ->assertJsonPath('data.late', 0);
    }

    public function test_rejected_leave_does_not_count_as_on_leave(): void
    {
        $this->freezeToday('2026-09-15');
        [$user, $employee] = $this->makeActiveUserAndEmployee();
        $this->makeScheduleAndPolicy($employee);

        $type = LeaveType::factory()->create(['code' => 'ANNUAL', 'name' => 'Annual Leave']);

        LeaveRequest::create([
            'employee_id' => $employee->id,
            'leave_type_id' => $type->id,
            'start_date' => '2026-09-15',
            'end_date' => '2026-09-15',
            'status' => 'rejected',
            'reason' => 'Vacation',
        ]);

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/dashboard/kpis')
            ->assertOk()
            ->assertJsonPath('data.on_leave', 0)
            ->assertJsonPath('data.absent', 1);
    }

    public function test_leave_takes_precedence_over_absent(): void
    {
        $this->freezeToday('2026-09-15');
        [$user, $employee] = $this->makeActiveUserAndEmployee();
        $this->makeScheduleAndPolicy($employee);

        $type = LeaveType::factory()->create(['code' => 'ANNUAL', 'name' => 'Annual Leave']);

        LeaveRequest::create([
            'employee_id' => $employee->id,
            'leave_type_id' => $type->id,
            'start_date' => '2026-09-15',
            'end_date' => '2026-09-15',
            'status' => 'approved',
            'reason' => 'Vacation',
        ]);

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/dashboard/kpis')
            ->assertOk()
            ->assertJsonPath('data.on_leave', 1)
            ->assertJsonPath('data.absent', 0);
    }

    // ========================
    // Employment eligibility
    // ========================

    public function test_permanent_employee_included(): void
    {
        $this->freezeToday('2026-09-15');
        [$user, $employee] = $this->makeActiveUserAndEmployee();
        $employee->update(['employment_status' => 'permanent']);
        $this->makeScheduleAndPolicy($employee);

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/dashboard/kpis')
            ->assertOk()
            ->assertJsonPath('data.absent', 1);
    }

    public function test_contract_employee_included(): void
    {
        $this->freezeToday('2026-09-15');
        [$user, $employee] = $this->makeActiveUserAndEmployee();
        $employee->update(['employment_status' => 'contract']);
        $this->makeScheduleAndPolicy($employee);

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/dashboard/kpis')
            ->assertOk()
            ->assertJsonPath('data.absent', 1);
    }

    public function test_probation_employee_included(): void
    {
        $this->freezeToday('2026-09-15');
        [$user, $employee] = $this->makeActiveUserAndEmployee();
        $employee->update(['employment_status' => 'probation']);
        $this->makeScheduleAndPolicy($employee);

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/dashboard/kpis')
            ->assertOk()
            ->assertJsonPath('data.absent', 1);
    }

    public function test_outsource_employee_included(): void
    {
        $this->freezeToday('2026-09-15');
        [$user, $employee] = $this->makeActiveUserAndEmployee();
        $employee->update(['employment_status' => 'outsource']);
        $this->makeScheduleAndPolicy($employee);

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/dashboard/kpis')
            ->assertOk()
            ->assertJsonPath('data.absent', 1);
    }

    // ========================
    // User isolation
    // ========================

    public function test_user_sees_own_kpi_only(): void
    {
        $this->freezeToday('2026-09-15');
        [$userA, $employeeA] = $this->makeActiveUserAndEmployee();
        [$userB, $employeeB] = $this->makeActiveUserAndEmployee();
        $this->makeScheduleAndPolicy($employeeA);
        $this->makeScheduleAndPolicy($employeeB);

        AttendanceRecord::create([
            'employee_id' => $employeeA->id,
            'attendance_date' => '2026-09-15',
            'status' => 'present',
        ]);
        AttendanceRecord::create([
            'employee_id' => $employeeB->id,
            'attendance_date' => '2026-09-15',
            'status' => 'present',
        ]);

        $this->actingAs($userA, 'sanctum')
            ->getJson('/api/v1/dashboard/kpis')
            ->assertOk()
            ->assertJsonPath('data.present', 1);
    }

    public function test_admin_sees_company_kpis(): void
    {
        $this->freezeToday('2026-09-15');
        [$userA, $employeeA] = $this->makeActiveUserAndEmployee();
        [$userB, $employeeB] = $this->makeActiveUserAndEmployee();
        $this->makeScheduleAndPolicy($employeeA);
        $this->makeScheduleAndPolicy($employeeB);

        AttendanceRecord::create([
            'employee_id' => $employeeA->id,
            'attendance_date' => '2026-09-15',
            'status' => 'present',
        ]);
        AttendanceRecord::create([
            'employee_id' => $employeeB->id,
            'attendance_date' => '2026-09-15',
            'status' => 'late',
        ]);

        $admin = $this->makeUser('ADMIN');
        Employee::factory()->create(['user_id' => $admin->id]);

        $this->actingAs($admin, 'sanctum')
            ->getJson('/api/v1/dashboard/kpis')
            ->assertOk()
            ->assertJsonPath('data.present', 1)
            ->assertJsonPath('data.late', 1);
    }

    // ========================
    // Response contract
    // ========================

    public function test_dashboard_kpis_response_shape(): void
    {
        $this->freezeToday('2026-09-15');
        [$user, $employee] = $this->makeActiveUserAndEmployee();
        $this->makeScheduleAndPolicy($employee);

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/dashboard/kpis')
            ->assertOk()
            ->assertJsonStructure([
                'data' => [
                    'date',
                    'present',
                    'absent',
                    'late',
                    'on_leave',
                    'incomplete',
                ],
            ])
            ->assertJsonPath('data.date', '2026-09-15')
            ->assertJsonPath('data.incomplete', 0);
    }

    public function test_dashboard_kpis_returns_integer_counts(): void
    {
        $this->freezeToday('2026-09-15');
        [$user, $employee] = $this->makeActiveUserAndEmployee();
        $this->makeScheduleAndPolicy($employee);

        $response = $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/dashboard/kpis')
            ->assertOk();

        foreach (['present', 'absent', 'late', 'on_leave', 'incomplete'] as $field) {
            $this->assertIsInt($response->json("data.{$field}"));
        }
    }

    // ========================
    // Multiple employees
    // ========================

    public function test_multiple_employees_counted_once_each(): void
    {
        $this->freezeToday('2026-09-15');
        [$user, $employeeA] = $this->makeActiveUserAndEmployee();
        [$userB, $employeeB] = $this->makeActiveUserAndEmployee();
        $this->makeScheduleAndPolicy($employeeA);
        $this->makeScheduleAndPolicy($employeeB);

        AttendanceRecord::create([
            'employee_id' => $employeeA->id,
            'attendance_date' => '2026-09-15',
            'status' => 'present',
        ]);
        AttendanceRecord::create([
            'employee_id' => $employeeB->id,
            'attendance_date' => '2026-09-15',
            'status' => 'present',
        ]);

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/dashboard/kpis')
            ->assertOk()
            ->assertJsonPath('data.present', 1);
    }

    // ========================
    // Mixed classifications
    // ========================

    public function test_mixed_classifications_aggregated_correctly(): void
    {
        $this->freezeToday('2026-09-15');
        [$user, $employeeA] = $this->makeActiveUserAndEmployee();
        [$userB, $employeeB] = $this->makeActiveUserAndEmployee();
        [$userC, $employeeC] = $this->makeActiveUserAndEmployee();
        [$userD, $employeeD] = $this->makeActiveUserAndEmployee();
        $this->makeScheduleAndPolicy($employeeA);
        $this->makeScheduleAndPolicy($employeeB);
        $this->makeScheduleAndPolicy($employeeC);
        $this->makeScheduleAndPolicy($employeeD);

        AttendanceRecord::create([
            'employee_id' => $employeeA->id,
            'attendance_date' => '2026-09-15',
            'status' => 'present',
        ]);
        AttendanceRecord::create([
            'employee_id' => $employeeB->id,
            'attendance_date' => '2026-09-15',
            'status' => 'late',
        ]);
        AttendanceRecord::create([
            'employee_id' => $employeeC->id,
            'attendance_date' => '2026-09-15',
            'status' => 'incomplete',
        ]);
        // employeeD has no attendance record -> absent

        $admin = $this->makeUser('ADMIN');
        Employee::factory()->create(['user_id' => $admin->id]);

        $this->actingAs($admin, 'sanctum')
            ->getJson('/api/v1/dashboard/kpis')
            ->assertOk()
            ->assertJsonPath('data.present', 2) // present + incomplete
            ->assertJsonPath('data.late', 1)
            ->assertJsonPath('data.absent', 1)
            ->assertJsonPath('data.on_leave', 0);
    }

    public function test_leave_precedence_over_absent(): void
    {
        $this->freezeToday('2026-09-15');
        [$user, $employee] = $this->makeActiveUserAndEmployee();
        $this->makeScheduleAndPolicy($employee);

        $type = LeaveType::factory()->create(['code' => 'ANNUAL', 'name' => 'Annual Leave']);

        LeaveRequest::create([
            'employee_id' => $employee->id,
            'leave_type_id' => $type->id,
            'start_date' => '2026-09-15',
            'end_date' => '2026-09-15',
            'status' => 'approved',
            'reason' => 'Vacation',
        ]);

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/dashboard/kpis')
            ->assertOk()
            ->assertJsonPath('data.on_leave', 1)
            ->assertJsonPath('data.absent', 0);
    }

    public function test_outsource_kpis_split_present_incomplete_absent(): void
    {
        $this->freezeToday('2026-09-15');
        $admin = $this->makeUser('ADMIN');

        $store = \App\Models\WorkLocation::factory()->create(['status' => 'active']);

        $presentPerson = \App\Models\Outsource::factory()->create(['status' => 'active']);
        $incompletePerson = \App\Models\Outsource::factory()->create(['status' => 'active']);
        $absentPerson = \App\Models\Outsource::factory()->create(['status' => 'active']);

        foreach ([$presentPerson, $incompletePerson, $absentPerson] as $person) {
            \App\Models\OutsourceStoreAssignment::factory()
                ->forOutsource($person)
                ->forStore($store)
                ->create(['status' => 'active']);
        }

        AttendanceRecord::factory()->create([
            'employee_id' => null,
            'outsource_id' => $presentPerson->id,
            'attendable_type' => 'outsource',
            'attendance_date' => '2026-09-15',
            'status' => 'present',
        ]);
        AttendanceRecord::factory()->create([
            'employee_id' => null,
            'outsource_id' => $incompletePerson->id,
            'attendable_type' => 'outsource',
            'attendance_date' => '2026-09-15',
            'status' => 'incomplete',
        ]);

        $this->actingAs($admin, 'sanctum')
            ->getJson('/api/v1/dashboard/kpis?source=outsource')
            ->assertOk()
            ->assertJsonPath('data.present', 1)
            ->assertJsonPath('data.incomplete', 1)
            ->assertJsonPath('data.absent', 1)
            ->assertJsonPath('data.late', 0)
            ->assertJsonPath('data.on_leave', 0);
    }
}
