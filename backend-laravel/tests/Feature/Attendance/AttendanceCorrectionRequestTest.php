<?php

namespace Tests\Feature\Attendance;

use App\Enums\AttendanceCorrectionStatus;
use App\Enums\AttendanceCorrectionType;
use App\Enums\AttendanceStatus;
use App\Enums\EmploymentStatus;
use App\Models\AttendanceCorrectionRequest;
use App\Models\AttendanceRecord;
use App\Models\AttendanceSession;
use App\Models\Employee;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AttendanceCorrectionRequestTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    /**
     * @return array{0: User, 1: Employee}
     */
    private function makeEmployeeUser(): array
    {
        $user = User::factory()->create();
        $user->assignRole('USER');
        $employee = Employee::factory()->create([
            'employment_status' => EmploymentStatus::Permanent->value,
            'user_id' => $user->id,
        ]);

        return [$user, $employee];
    }

    private function makeAdmin(): User
    {
        $admin = User::factory()->create();
        $admin->assignRole('ADMIN');

        return $admin;
    }

    public function test_employee_can_create_clock_out_correction_request(): void
    {
        [$user, $employee] = $this->makeEmployeeUser();
        $date = now()->subDay()->toDateString();

        $record = AttendanceRecord::factory()->forEmployee($employee)->onDate($date)->status(AttendanceStatus::Incomplete->value)->create();
        AttendanceSession::factory()->create([
            'attendance_record_id' => $record->id,
            'check_in_at' => $date.' 08:00:00',
            'check_out_at' => null,
            'status' => 'open',
        ]);

        $response = $this->actingAs($user, 'sanctum')->postJson('/api/v1/attendance/correction-requests', [
            'request_type' => AttendanceCorrectionType::ClockOut->value,
            'attendance_date' => $date,
            'requested_check_out_at' => $date.'T17:05:00+07:00',
            'reason' => 'Forgot to clock out after finishing work.',
        ]);

        $response->assertCreated();
        $response->assertJsonPath('data.status', AttendanceCorrectionStatus::Pending->value);
        $this->assertDatabaseHas('attendance_correction_requests', [
            'employee_id' => $employee->id,
            'request_type' => AttendanceCorrectionType::ClockOut->value,
            'status' => AttendanceCorrectionStatus::Pending->value,
        ]);
    }

    public function test_admin_can_approve_correction_and_close_session(): void
    {
        [$user, $employee] = $this->makeEmployeeUser();
        $admin = $this->makeAdmin();
        $date = now()->subDay()->toDateString();

        $record = AttendanceRecord::factory()->forEmployee($employee)->onDate($date)->status(AttendanceStatus::Incomplete->value)->create();
        $session = AttendanceSession::factory()->create([
            'attendance_record_id' => $record->id,
            'check_in_at' => $date.' 08:00:00',
            'check_out_at' => null,
            'status' => 'open',
        ]);

        $correction = AttendanceCorrectionRequest::factory()->forEmployee($employee)->create([
            'attendance_record_id' => $record->id,
            'attendance_session_id' => $session->id,
            'request_type' => AttendanceCorrectionType::ClockOut->value,
            'attendance_date' => $date,
            'requested_check_in_at' => null,
            'requested_check_out_at' => $date.' 17:00:00',
            'reason' => 'Forgot to clock out after finishing work.',
        ]);

        $response = $this->actingAs($admin, 'sanctum')
            ->postJson("/api/v1/attendance/correction-requests/{$correction->id}/approve");

        $response->assertOk();
        $response->assertJsonPath('data.status', AttendanceCorrectionStatus::Approved->value);

        $session->refresh();
        $record->refresh();
        $this->assertSame('closed', $session->status);
        $this->assertNotNull($session->check_out_at);
        $this->assertSame(AttendanceStatus::Present->value, $record->status);
    }

    public function test_employee_can_cancel_own_pending_request(): void
    {
        [$user, $employee] = $this->makeEmployeeUser();
        $correction = AttendanceCorrectionRequest::factory()->forEmployee($employee)->pending()->create();

        $this->actingAs($user, 'sanctum')
            ->postJson("/api/v1/attendance/correction-requests/{$correction->id}/cancel")
            ->assertOk()
            ->assertJsonPath('data.status', AttendanceCorrectionStatus::Cancelled->value);
    }

    public function test_admin_without_employee_record_can_list_corrections(): void
    {
        $admin = $this->makeAdmin();
        [$user, $employee] = $this->makeEmployeeUser();
        AttendanceCorrectionRequest::factory()->forEmployee($employee)->pending()->create();

        $this->actingAs($admin, 'sanctum')
            ->getJson('/api/v1/attendance/correction-requests?per_page=25&status=pending')
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.employee_name', $employee->full_name);
    }
}
