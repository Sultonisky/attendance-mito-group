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
use Tests\TestCase;

class DashboardStaffAndTrendTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    private function makeAdmin(): User
    {
        $user = User::factory()->create();
        $user->assignRole('ADMIN');

        return $user;
    }

    private function makeScheduledEmployee(): Employee
    {
        $employee = Employee::factory()->create([
            'branch' => 'Jakarta',
            'email' => 'staff.demo@example.com',
        ]);

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

        return $employee;
    }

    public function test_staff_today_requires_auth(): void
    {
        $this->getJson('/api/v1/dashboard/staff-today')->assertUnauthorized();
    }

    public function test_staff_today_returns_eligible_rows(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-09-15 10:00:00'));
        $admin = $this->makeAdmin();
        $employee = $this->makeScheduledEmployee();

        AttendanceRecord::factory()
            ->forEmployee($employee)
            ->onDate('2026-09-15')
            ->status('present')
            ->create();

        $this->actingAs($admin, 'sanctum')
            ->getJson('/api/v1/dashboard/staff-today')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonFragment([
                'id' => $employee->id,
                'code' => $employee->employee_code,
                'name' => $employee->full_name,
                'location' => 'Jakarta',
                'status' => 'Present',
            ]);
    }

    public function test_staff_today_marks_approved_leave(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-09-15 10:00:00'));
        $admin = $this->makeAdmin();
        $employee = $this->makeScheduledEmployee();
        $leaveType = LeaveType::factory()->create();

        LeaveRequest::factory()
            ->forEmployee($employee)
            ->approved()
            ->onDate('2026-09-15')
            ->create(['leave_type_id' => $leaveType->id]);

        $this->actingAs($admin, 'sanctum')
            ->getJson('/api/v1/dashboard/staff-today')
            ->assertOk()
            ->assertJsonFragment([
                'id' => $employee->id,
                'status' => 'On leave',
            ]);
    }

    public function test_attendance_trend_requires_from_to(): void
    {
        $admin = $this->makeAdmin();

        $this->actingAs($admin, 'sanctum')
            ->getJson('/api/v1/dashboard/attendance-trend')
            ->assertUnprocessable();
    }

    public function test_attendance_trend_returns_daily_points(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-09-15 10:00:00'));
        $admin = $this->makeAdmin();
        $employee = $this->makeScheduledEmployee();

        AttendanceRecord::factory()
            ->forEmployee($employee)
            ->onDate('2026-09-14')
            ->status('present')
            ->create();

        $this->actingAs($admin, 'sanctum')
            ->getJson('/api/v1/dashboard/attendance-trend?from=2026-09-14&to=2026-09-15')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.0.date', '2026-09-14')
            ->assertJsonPath('data.1.date', '2026-09-15')
            ->assertJsonPath('data.0.present', 1);
    }
}
