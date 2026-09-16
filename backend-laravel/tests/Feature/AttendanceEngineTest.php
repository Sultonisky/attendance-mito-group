<?php

namespace Tests\Feature\Attendance;

use App\Enums\AttendanceEventType;
use App\Enums\AttendanceSessionStatus;
use App\Enums\AttendanceStatus;
use App\Models\AttendanceRecord;
use App\Models\AttendanceSession;
use App\Models\AuditLog;
use App\Models\Employee;
use App\Models\EmployeeFaceEmbedding;
use App\Models\EmployeeFaceProfile;
use App\Models\Policy;
use App\Models\PolicyAssignment;
use App\Models\ScheduleAssignment;
use App\Models\Shift;
use App\Models\User;
use App\Models\WorkSchedule;
use Carbon\CarbonImmutable;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Testing\File;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class AttendanceEngineTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);
    }

    private function makeEmployee(array $overrides = []): Employee
    {
        return Employee::factory()->create(array_merge([
            'employment_status' => 'permanent',
        ], $overrides));
    }

    private function makeWorkSchedule(array $overrides = []): WorkSchedule
    {
        return WorkSchedule::factory()->create($overrides);
    }

    private function makeShift(WorkSchedule $schedule, array $overrides = []): Shift
    {
        return Shift::factory()->create(array_merge([
            'work_schedule_id' => $schedule->id,
        ], $overrides));
    }

    private function makeScheduleAssignment(Employee $employee, WorkSchedule $schedule, array $overrides = []): ScheduleAssignment
    {
        return ScheduleAssignment::factory()->create(array_merge([
            'employee_id' => $employee->id,
            'work_schedule_id' => $schedule->id,
        ], $overrides));
    }

    private function makePolicyAssignment(Employee $employee, Policy $policy, array $overrides = []): PolicyAssignment
    {
        return PolicyAssignment::factory()->create(array_merge([
            'employee_id' => $employee->id,
            'policy_id' => $policy->id,
        ], $overrides));
    }

    private function userForEmployee(Employee $employee, array $overrides = []): User
    {
        return User::firstOrCreate(
            ['email' => $employee->email],
            array_merge([
                'name' => $employee->full_name,
                'password' => Hash::make('password'),
            ], $overrides)
        );
    }

    /**
     * An inactive employee cannot check in.
     */
    public function test_inactive_employee_cannot_check_in(): void
    {
        $employee = $this->makeEmployee([
            'employment_status' => 'resigned',
            'end_date' => now()->subDay()->toDateString(),
        ]);

        $response = $this->actingAs($this->userForEmployee($employee), 'sanctum')
            ->postJson('/api/v1/attendance/check-in', [
                'latitude' => -6.2,
                'longitude' => 106.8,
            ]);

        $response->assertStatus(422);
        $response->assertJsonPath('data.error', 'Employee employment status is inactive.');
    }

    /**
     * An employee without a schedule cannot check in.
     */
    public function test_employee_without_schedule_cannot_check_in(): void
    {
        $employee = $this->makeEmployee();

        $response = $this->actingAs($this->userForEmployee($employee), 'sanctum')
            ->postJson('/api/v1/attendance/check-in', [
                'latitude' => -6.2,
                'longitude' => 106.8,
            ]);

        $response->assertStatus(422);
        $response->assertJsonPath('data.error', 'No active schedule found for this date.');
    }

    /**
     * A valid check-in creates an attendance record and an open session.
     */
    public function test_valid_check_in_creates_record_and_session(): void
    {
        $employee = $this->makeEmployee();
        $schedule = $this->makeWorkSchedule();
        $shift = $this->makeShift($schedule, [
            'start_time' => '08:00:00',
            'end_time' => '17:00:00',
        ]);
        $this->makeScheduleAssignment($employee, $schedule);

        $response = $this->actingAs($this->userForEmployee($employee), 'sanctum')
            ->postJson('/api/v1/attendance/check-in', [
                'latitude' => -6.2,
                'longitude' => 106.8,
            ], [
                'X-Occurred-At' => CarbonImmutable::create(2026, 9, 12, 7, 59, 0)->toIso8601String(),
            ])
            ->assertStatus(201)
            ->assertJsonPath('data.status', AttendanceStatus::Present->value);

        $this->assertDatabaseHas('attendance_records', [
            'employee_id' => $employee->id,
        ]);

        $record = AttendanceRecord::where('employee_id', $employee->id)->first();

        $this->assertNotNull($record);
        $this->assertDatabaseHas('attendance_sessions', [
            'attendance_record_id' => $record->id,
            'status' => AttendanceSessionStatus::Open->value,
        ]);

        $this->assertDatabaseHas('attendance_events', [
            'employee_id' => $employee->id,
            'event_type' => AttendanceEventType::CheckIn->value,
        ]);
    }

    /**
     * A valid check-out closes the open session and creates a check-out event.
     */
    public function test_valid_check_out_closes_session(): void
    {
        $employee = $this->makeEmployee();
        $schedule = $this->makeWorkSchedule();
        $shift = $this->makeShift($schedule, [
            'start_time' => '08:00:00',
            'end_time' => '17:00:00',
        ]);
        $this->makeScheduleAssignment($employee, $schedule);

        $checkInResponse = $this->actingAs($this->userForEmployee($employee), 'sanctum')
            ->postJson('/api/v1/attendance/check-in', [
                'latitude' => -6.2,
                'longitude' => 106.8,
            ], [
                'X-Occurred-At' => CarbonImmutable::create(2026, 9, 12, 7, 59, 0)->toIso8601String(),
            ]);

        $checkInResponse->assertStatus(201);

        $response = $this->actingAs($this->userForEmployee($employee), 'sanctum')
            ->postJson('/api/v1/attendance/check-out', [
                'latitude' => -6.2,
                'longitude' => 106.8,
            ], [
                'X-Occurred-At' => CarbonImmutable::create(2026, 9, 12, 17, 1, 0)->toIso8601String(),
            ]);

        $response->assertStatus(200);
        $response->assertJsonPath('data.status', AttendanceStatus::Present->value);

        $record = AttendanceRecord::where('employee_id', $employee->id)->first();

        $this->assertNotNull($record);
        $session = $record->sessions()->first();
        $this->assertEquals(AttendanceSessionStatus::Closed->value, $session->status);
        $this->assertNotNull($session->check_out_at);
        $this->assertNotNull($session->duration_minutes);

        $this->assertDatabaseHas('attendance_events', [
            'employee_id' => $employee->id,
            'event_type' => AttendanceEventType::CheckOut->value,
        ]);
    }

    /**
     * Multiple check-in/out sessions in a day create multiple sessions.
     */
    public function test_multiple_sessions_in_a_day(): void
    {
        $employee = $this->makeEmployee();
        $schedule = $this->makeWorkSchedule();
        $shift = $this->makeShift($schedule, [
            'start_time' => '08:00:00',
            'end_time' => '12:00:00',
        ]);
        $this->makeScheduleAssignment($employee, $schedule);

        $this->actingAs($this->userForEmployee($employee), 'sanctum')
            ->postJson('/api/v1/attendance/check-in', [
                'latitude' => -6.2,
                'longitude' => 106.8,
            ])->assertStatus(201);

        $this->actingAs($this->userForEmployee($employee), 'sanctum')
            ->postJson('/api/v1/attendance/check-out', [
                'latitude' => -6.2,
                'longitude' => 106.8,
            ])->assertStatus(200);

        $this->actingAs($this->userForEmployee($employee), 'sanctum')
            ->postJson('/api/v1/attendance/check-in', [
                'latitude' => -6.2,
                'longitude' => 106.8,
            ])->assertStatus(201);

        $record = AttendanceRecord::where('employee_id', $employee->id)->first();

        $this->assertNotNull($record);
        $this->assertEquals(2, $record->sessions()->count());
    }

    /**
     * A policy that blocks check-in prevents the action.
     */
    public function test_policy_can_block_check_in(): void
    {
        $employee = $this->makeEmployee();
        $schedule = $this->makeWorkSchedule();
        $shift = $this->makeShift($schedule, [
            'start_time' => '08:00:00',
            'end_time' => '17:00:00',
        ]);
        $this->makeScheduleAssignment($employee, $schedule);

        $policy = Policy::factory()->create([
            'configuration' => [
                'attendance' => [
                    'check_in_blocked' => true,
                ],
            ],
        ]);
        $this->makePolicyAssignment($employee, $policy);

        $response = $this->actingAs($this->userForEmployee($employee), 'sanctum')
            ->postJson('/api/v1/attendance/check-in', [
                'latitude' => -6.2,
                'longitude' => 106.8,
            ]);

        $response->assertStatus(422);
        $response->assertJsonPath('data.error', $policy->name);
    }

    /**
     * A policy that blocks check-out prevents the action.
     */
    public function test_policy_can_block_check_out(): void
    {
        $employee = $this->makeEmployee();
        $schedule = $this->makeWorkSchedule();
        $shift = $this->makeShift($schedule, [
            'start_time' => '08:00:00',
            'end_time' => '17:00:00',
        ]);
        $this->makeScheduleAssignment($employee, $schedule);

        $policy = Policy::factory()->create([
            'configuration' => [
                'attendance' => [
                    'check_out_blocked' => true,
                ],
            ],
        ]);
        $this->makePolicyAssignment($employee, $policy);

        $this->actingAs($this->userForEmployee($employee), 'sanctum')
            ->postJson('/api/v1/attendance/check-in', [
                'latitude' => -6.2,
                'longitude' => 106.8,
            ])->assertStatus(201);

        $response = $this->actingAs($this->userForEmployee($employee), 'sanctum')
            ->postJson('/api/v1/attendance/check-out', [
                'latitude' => -6.2,
                'longitude' => 106.8,
            ]);

        $response->assertStatus(422);
        $response->assertJsonPath('data.error', $policy->name);
    }

    /**
     * Cross-midnight shifts are handled correctly for late night check-ins.
     */
    public function test_cross_midnight_shift_check_in(): void
    {
        $employee = $this->makeEmployee();
        $schedule = $this->makeWorkSchedule();
        $shift = $this->makeShift($schedule, [
            'start_time' => '22:00:00',
            'end_time' => '06:00:00',
            'cross_midnight' => true,
        ]);
        $this->makeScheduleAssignment($employee, $schedule);

        $response = $this->actingAs($this->userForEmployee($employee), 'sanctum')
            ->postJson('/api/v1/attendance/check-in', [
                'latitude' => -6.2,
                'longitude' => 106.8,
            ], [
                'X-Occurred-At' => CarbonImmutable::create(2026, 9, 12, 21, 30, 0)->toIso8601String(),
            ]);

        $response->assertStatus(201);
        $response->assertJsonPath('data.status', AttendanceStatus::Present->value);
    }

    /**
     * A user cannot check out without a prior check-in.
     */
    public function test_check_out_without_open_session_fails(): void
    {
        $employee = $this->makeEmployee();

        $response = $this->actingAs($this->userForEmployee($employee), 'sanctum')
            ->postJson('/api/v1/attendance/check-out', [
                'latitude' => -6.2,
                'longitude' => 106.8,
            ]);

        $response->assertStatus(422);
        $response->assertJsonPath('data.error', 'No open attendance session found.');
    }

    /**
     * The attendance index returns only the authenticated employee's records.
     */
    public function test_attendance_index_returns_own_records(): void
    {
        $employee = $this->makeEmployee();
        $otherEmployee = $this->makeEmployee();

        AttendanceRecord::factory()->create(['employee_id' => $employee->id]);
        AttendanceRecord::factory()->create(['employee_id' => $otherEmployee->id]);

        $user = $this->userForEmployee($employee);
        $user->assignRole('USER');

        $response = $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/attendance');

        $response->assertStatus(200);
        $this->assertGreaterThanOrEqual(1, $response->json('meta.total'));
        $this->assertCount(1, $response->json('data') ?? []);
    }

    /**
     * The attendance show endpoint returns the requested record for the owner.
     */
    public function test_attendance_show_returns_own_record(): void
    {
        $employee = $this->makeEmployee();
        $record = AttendanceRecord::factory()->create(['employee_id' => $employee->id]);

        $user = $this->userForEmployee($employee);
        $user->assignRole('USER');

        $response = $this->actingAs($user, 'sanctum')
            ->getJson("/api/v1/attendance/{$record->id}");

        $response->assertStatus(200);
        $response->assertJsonPath('data.id', $record->id);
    }

    /**
     * The attendance show endpoint returns 404 for another employee's record.
     */
    public function test_attendance_show_returns_404_for_other_employee(): void
    {
        $employee = $this->makeEmployee();
        $otherEmployee = $this->makeEmployee();
        $record = AttendanceRecord::factory()->create(['employee_id' => $otherEmployee->id]);

        $user = $this->userForEmployee($employee);
        $user->assignRole('USER');

        $response = $this->actingAs($user, 'sanctum')
            ->getJson("/api/v1/attendance/{$record->id}");

        $response->assertStatus(404);
    }

    /**
     * Authenticated but unlinked user receives 404 on check-in.
     */
    public function test_check_in_requires_employee_link(): void
    {
        $user = User::factory()->create([
            'email' => 'no-employee@example.com',
            'password' => Hash::make('password'),
        ]);

        $response = $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/attendance/check-in', [
                'latitude' => -6.2,
                'longitude' => 106.8,
            ]);

        $response->assertStatus(404);
    }

    /**
     * Check-in creates an audit log with the correct action and actor.
     */
    public function test_check_in_creates_audit_log(): void
    {
        $employee = $this->makeEmployee();
        $schedule = $this->makeWorkSchedule();
        $this->makeShift($schedule, [
            'start_time' => '08:00:00',
            'end_time' => '17:00:00',
        ]);
        $this->makeScheduleAssignment($employee, $schedule);
        $user = $this->userForEmployee($employee);

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/attendance/check-in', [
                'latitude' => -6.2,
                'longitude' => 106.8,
            ])
            ->assertStatus(201);

        $record = AttendanceRecord::where('employee_id', $employee->id)->first();
        $this->assertNotNull($record);

        $log = AuditLog::where('action', 'attendance.check_in')
            ->where('auditable_type', AttendanceRecord::class)
            ->where('auditable_id', $record->id)
            ->first();

        $this->assertNotNull($log);
        $this->assertSame($user->id, $log->actor_id);
        $this->assertNotNull($log->new_values);
        $this->assertSame($record->status, $log->new_values['status']);
        $this->assertSame('open', $log->new_values['session_status']);
    }

    /**
     * Check-out creates an audit log with the correct action and actor.
     */
    public function test_check_out_creates_audit_log(): void
    {
        $employee = $this->makeEmployee();
        $schedule = $this->makeWorkSchedule();
        $this->makeShift($schedule, [
            'start_time' => '08:00:00',
            'end_time' => '17:00:00',
        ]);
        $this->makeScheduleAssignment($employee, $schedule);
        $user = $this->userForEmployee($employee);

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/attendance/check-in', [
                'latitude' => -6.2,
                'longitude' => 106.8,
            ])
            ->assertStatus(201);

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/attendance/check-out', [
                'latitude' => -6.2,
                'longitude' => 106.8,
            ])
            ->assertStatus(200);

        $session = AttendanceSession::where('status', AttendanceSessionStatus::Closed->value)->first();
        $this->assertNotNull($session);

        $log = AuditLog::where('action', 'attendance.check_out')
            ->where('auditable_type', AttendanceSession::class)
            ->where('auditable_id', $session->id)
            ->first();

        $this->assertNotNull($log);
        $this->assertSame($user->id, $log->actor_id);
        $this->assertNotNull($log->old_values);
        $this->assertNotNull($log->new_values);
        $this->assertSame('closed', $log->new_values['status']);
        $this->assertNotNull($log->new_values['check_out_at']);
    }

    /**
     * Cross-midnight next-day check-out closes the previous day's session.
     */
    public function test_cross_midnight_check_out_succeeds(): void
    {
        $employee = $this->makeEmployee();
        $schedule = $this->makeWorkSchedule();
        $this->makeShift($schedule, [
            'start_time' => '22:00:00',
            'end_time' => '06:00:00',
            'cross_midnight' => true,
        ]);
        $this->makeScheduleAssignment($employee, $schedule);

        $checkInTime = CarbonImmutable::create(2026, 9, 12, 23, 0, 0);

        $checkInResponse = $this->actingAs($this->userForEmployee($employee), 'sanctum')
            ->postJson('/api/v1/attendance/check-in', [
                'latitude' => -6.2,
                'longitude' => 106.8,
            ], [
                'X-Occurred-At' => $checkInTime->toIso8601String(),
            ]);

        $checkInResponse->assertStatus(201);
        $record = AttendanceRecord::where('employee_id', $employee->id)->first();
        $this->assertNotNull($record);
        $this->assertSame('2026-09-12', $record->attendance_date->toDateString());

        $checkOutTime = CarbonImmutable::create(2026, 9, 13, 1, 0, 0);

        $checkOutResponse = $this->actingAs($this->userForEmployee($employee), 'sanctum')
            ->postJson('/api/v1/attendance/check-out', [
                'latitude' => -6.2,
                'longitude' => 106.8,
            ], [
                'X-Occurred-At' => $checkOutTime->toIso8601String(),
            ]);

        $checkOutResponse->assertStatus(200);
        $record->refresh();
        $session = $record->sessions()->first();
        $this->assertEquals(AttendanceSessionStatus::Closed->value, $session->status);
        $this->assertNotNull($session->check_out_at);
        $this->assertNotNull($session->duration_minutes);
    }

    private function test_image(): File
    {
        return File::image('face.jpg', 200, 200);
    }

    private function faceProfileFor(Employee $employee, string $modelVersion = 'face-dev-v1', string $embeddingRef = 'test_ref'): EmployeeFaceProfile
    {
        $profile = EmployeeFaceProfile::create([
            'employee_id' => $employee->id,
            'model_version' => $modelVersion,
            'status' => 'active',
        ]);

        EmployeeFaceEmbedding::create([
            'employee_face_profile_id' => $profile->id,
            'model_version' => $modelVersion,
            'embedding_reference' => $embeddingRef,
            'provider' => 'fastapi',
            'status' => 'active',
        ]);

        return $profile;
    }

    /**
     * Check-in succeeds when the employee has an active face profile and
     * FastAPI returns a successful verification.
     */
    public function test_check_in_with_face_verification_succeeds(): void
    {
        $employee = $this->makeEmployee();
        $schedule = $this->makeWorkSchedule();
        $this->makeShift($schedule, [
            'start_time' => '08:00:00',
            'end_time' => '17:00:00',
        ]);
        $this->makeScheduleAssignment($employee, $schedule);
        $this->faceProfileFor($employee);

        Http::fake([
            '*/face/verify' => Http::response([
                'verified' => true,
                'confidence' => 0.94,
                'liveness' => true,
                'liveness_reason' => null,
                'face_detected' => true,
                'model_version' => 'face-dev-v1',
                'processing_time_ms' => 45,
                'quality_score' => 0.88,
            ], 200),
        ]);

        $checkInAt = CarbonImmutable::create(2026, 9, 12, 7, 59, 0);

        $response = $this->actingAs($this->userForEmployee($employee), 'sanctum')
            ->postJson('/api/v1/attendance/check-in', [
                'latitude' => -6.2,
                'longitude' => 106.8,
                'face_image' => $this->test_image(),
            ], [
                'X-Occurred-At' => $checkInAt->toIso8601String(),
            ]);

        $response->assertStatus(201);
        $response->assertJsonPath('data.status', AttendanceStatus::Present->value);

        $record = AttendanceRecord::where('employee_id', $employee->id)->first();
        $this->assertNotNull($record);

        $this->assertDatabaseHas('attendance_verifications', [
            'employee_id' => $employee->id,
            'attendance_id' => $record->id,
            'verification_type' => 'face',
            'status' => 'passed',
        ]);
    }

    /**
     * Check-in is rejected when face verification fails (verified=false).
     */
    public function test_check_in_rejected_when_face_verification_fails(): void
    {
        $employee = $this->makeEmployee();
        $schedule = $this->makeWorkSchedule();
        $this->makeShift($schedule, [
            'start_time' => '08:00:00',
            'end_time' => '17:00:00',
        ]);
        $this->makeScheduleAssignment($employee, $schedule);
        $this->faceProfileFor($employee);

        Http::fake([
            '*/face/verify' => Http::response([
                'verified' => false,
                'confidence' => 0.3,
                'liveness' => true,
                'liveness_reason' => null,
                'face_detected' => true,
                'model_version' => 'face-dev-v1',
                'processing_time_ms' => 35,
                'quality_score' => 0.6,
            ], 200),
        ]);

        $checkInAt = CarbonImmutable::create(2026, 9, 12, 7, 59, 0);

        $response = $this->actingAs($this->userForEmployee($employee), 'sanctum')
            ->postJson('/api/v1/attendance/check-in', [
                'latitude' => -6.2,
                'longitude' => 106.8,
                'face_image' => $this->test_image(),
            ], [
                'X-Occurred-At' => $checkInAt->toIso8601String(),
            ]);

        $response->assertStatus(422);
        $response->assertJsonPath('data.error', 'Face verification failed.');

        $this->assertDatabaseMissing('attendance_records', [
            'employee_id' => $employee->id,
        ]);
    }

    /**
     * Check-in is rejected when FastAPI is unavailable.
     */
    public function test_check_in_rejected_when_ai_service_unavailable(): void
    {
        $employee = $this->makeEmployee();
        $schedule = $this->makeWorkSchedule();
        $this->makeShift($schedule, [
            'start_time' => '08:00:00',
            'end_time' => '17:00:00',
        ]);
        $this->makeScheduleAssignment($employee, $schedule);
        $this->faceProfileFor($employee);

        Http::fake([
            '*/face/verify' => Http::failedConnection(),
        ]);

        $checkInAt = CarbonImmutable::create(2026, 9, 12, 7, 59, 0);

        $response = $this->actingAs($this->userForEmployee($employee), 'sanctum')
            ->postJson('/api/v1/attendance/check-in', [
                'latitude' => -6.2,
                'longitude' => 106.8,
                'face_image' => $this->test_image(),
            ], [
                'X-Occurred-At' => $checkInAt->toIso8601String(),
            ]);

        $response->assertStatus(422);
        $response->assertJsonPath('data.error', 'Face verification failed.');

        $this->assertDatabaseMissing('attendance_records', [
            'employee_id' => $employee->id,
        ]);
    }

    /**
     * Check-in is rejected when liveness is required but fails.
     */
    public function test_check_in_rejected_when_liveness_fails(): void
    {
        $employee = $this->makeEmployee();
        $schedule = $this->makeWorkSchedule();
        $this->makeShift($schedule, [
            'start_time' => '08:00:00',
            'end_time' => '17:00:00',
        ]);
        $this->makeScheduleAssignment($employee, $schedule);
        $this->faceProfileFor($employee);

        Http::fake([
            '*/face/verify' => Http::response([
                'verified' => true,
                'confidence' => 0.95,
                'liveness' => false,
                'liveness_reason' => 'no_blink_detected',
                'face_detected' => true,
                'model_version' => 'face-dev-v1',
                'processing_time_ms' => 50,
                'quality_score' => 0.92,
            ], 200),
        ]);

        $checkInAt = CarbonImmutable::create(2026, 9, 12, 7, 59, 0);

        $response = $this->actingAs($this->userForEmployee($employee), 'sanctum')
            ->postJson('/api/v1/attendance/check-in', [
                'latitude' => -6.2,
                'longitude' => 106.8,
                'face_image' => $this->test_image(),
            ], [
                'X-Occurred-At' => $checkInAt->toIso8601String(),
            ]);

        $response->assertStatus(422);
        $response->assertJsonPath('data.error', 'Face verification failed.');

        $this->assertDatabaseMissing('attendance_records', [
            'employee_id' => $employee->id,
        ]);
    }

    /**
     * Check-in succeeds without face image when the employee has no active
     * face profile (verification is skipped).
     */
    public function test_check_in_skips_face_verification_when_no_profile(): void
    {
        $employee = $this->makeEmployee();
        $schedule = $this->makeWorkSchedule();
        $this->makeShift($schedule, [
            'start_time' => '08:00:00',
            'end_time' => '17:00:00',
        ]);
        $this->makeScheduleAssignment($employee, $schedule);

        $checkInAt = CarbonImmutable::create(2026, 9, 12, 7, 59, 0);

        $response = $this->actingAs($this->userForEmployee($employee), 'sanctum')
            ->postJson('/api/v1/attendance/check-in', [
                'latitude' => -6.2,
                'longitude' => 106.8,
            ], [
                'X-Occurred-At' => $checkInAt->toIso8601String(),
            ]);

        $response->assertStatus(201);
        $response->assertJsonPath('data.status', AttendanceStatus::Present->value);

        $record = AttendanceRecord::where('employee_id', $employee->id)->first();
        $this->assertNotNull($record);

        $this->assertDatabaseMissing('attendance_verifications', [
            'employee_id' => $employee->id,
            'verification_type' => 'face',
        ]);
    }
}
