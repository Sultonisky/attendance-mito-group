<?php

namespace Tests\Feature\Attendance;

use App\Models\AttendanceRecord;
use App\Models\Employee;
use App\Models\EmployeeFaceEmbedding;
use App\Models\EmployeeFaceProfile;
use App\Models\Policy;
use App\Models\PolicyAssignment;
use App\Models\ScheduleAssignment;
use App\Models\Shift;
use App\Models\User;
use App\Models\WorkLocation;
use App\Models\WorkSchedule;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Testing\File;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class FaceVerificationTest extends TestCase
{
    use RefreshDatabase;

    private function makeUser(array $overrides = []): User
    {
        return User::factory()->create($overrides);
    }

    private function makeEmployee(array $overrides = []): Employee
    {
        return Employee::factory()->create($overrides);
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

    private function makeWorkLocation(): WorkLocation
    {
        return WorkLocation::factory()->create([
            'latitude' => -6.2,
            'longitude' => 106.8,
            'radius_meters' => 1000,
        ]);
    }

    private function makeScheduleAndPolicy(Employee $employee): void
    {
        $schedule = WorkSchedule::factory()->create();
        Shift::factory()->forSchedule($schedule)->create([
            'start_time' => '08:00:00',
            'end_time' => '17:00:00',
        ]);
        ScheduleAssignment::factory()->forEmployee($employee)->forSchedule($schedule)->create([
            'effective_from' => now()->subYear()->toDateString(),
            'effective_to' => null,
        ]);

        $policy = Policy::factory()->create();
        PolicyAssignment::factory()->forEmployee($employee)->forPolicy($policy)->create([
            'effective_from' => now()->subYear()->toDateString(),
            'effective_to' => null,
        ]);
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

    private function test_image(): File
    {
        return File::image('face.jpg', 200, 200);
    }

    private function fakeFaceApiSuccess(): void
    {
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
    }

    private function fakeFaceApiFailure(): void
    {
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
    }

    private function fakeFaceApiUnavailable(): void
    {
        Http::fake([
            '*/face/verify' => Http::failedConnection(),
        ]);
    }

    private function postCheckIn(Employee $employee, array $payload = []): TestResponse
    {
        return $this->actingAs($this->userForEmployee($employee), 'sanctum')
            ->postJson('/api/v1/attendance/check-in', array_merge([
                'latitude' => -6.2001,
                'longitude' => 106.8001,
                'accuracy' => 12.5,
            ], $payload));
    }

    public function test_face_verification_success_creates_face_verification_record(): void
    {
        $employee = $this->makeEmployee();
        $this->makeWorkLocation();
        $this->makeScheduleAndPolicy($employee);
        $this->faceProfileFor($employee);
        $this->fakeFaceApiSuccess();

        $checkInAt = CarbonImmutable::create(2026, 9, 12, 7, 59, 0, 'Asia/Jakarta');

        $response = $this->postCheckIn($employee, [
            'face_image' => $this->test_image(),
        ], [
            'X-Occurred-At' => $checkInAt->toIso8601String(),
        ]);

        $response->assertStatus(201);

        $record = AttendanceRecord::where('employee_id', $employee->id)->first();
        $this->assertNotNull($record);

        $this->assertDatabaseHas('attendance_verifications', [
            'employee_id' => $employee->id,
            'attendance_id' => $record->id,
            'verification_type' => 'face',
            'status' => 'passed',
        ]);
    }

    public function test_face_verification_failure_prevents_attendance_mutation(): void
    {
        $employee = $this->makeEmployee();
        $this->makeWorkLocation();
        $this->makeScheduleAndPolicy($employee);
        $this->faceProfileFor($employee);
        $this->fakeFaceApiFailure();

        $checkInAt = CarbonImmutable::create(2026, 9, 12, 7, 59, 0, 'Asia/Jakarta');

        $response = $this->postCheckIn($employee, [
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

    public function test_face_verification_failure_does_not_create_face_verification_record(): void
    {
        $employee = $this->makeEmployee();
        $this->makeWorkLocation();
        $this->makeScheduleAndPolicy($employee);
        $this->faceProfileFor($employee);
        $this->fakeFaceApiFailure();

        $checkInAt = CarbonImmutable::create(2026, 9, 12, 7, 59, 0, 'Asia/Jakarta');

        $this->postCheckIn($employee, [
            'face_image' => $this->test_image(),
        ], [
            'X-Occurred-At' => $checkInAt->toIso8601String(),
        ]);

        $this->assertDatabaseMissing('attendance_verifications', [
            'employee_id' => $employee->id,
            'verification_type' => 'face',
        ]);
    }

    public function test_face_verification_unavailable_prevents_attendance(): void
    {
        $employee = $this->makeEmployee();
        $this->makeWorkLocation();
        $this->makeScheduleAndPolicy($employee);
        $this->faceProfileFor($employee);
        $this->fakeFaceApiUnavailable();

        $checkInAt = CarbonImmutable::create(2026, 9, 12, 7, 59, 0, 'Asia/Jakarta');

        $response = $this->postCheckIn($employee, [
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

    public function test_face_verification_skipped_when_no_profile(): void
    {
        $employee = $this->makeEmployee();
        $this->makeWorkLocation();
        $this->makeScheduleAndPolicy($employee);
        // No face profile created

        $checkInAt = CarbonImmutable::create(2026, 9, 12, 7, 59, 0, 'Asia/Jakarta');

        $response = $this->postCheckIn($employee, [
            'face_image' => $this->test_image(),
        ], [
            'X-Occurred-At' => $checkInAt->toIso8601String(),
        ]);

        $response->assertStatus(201);

        $this->assertDatabaseMissing('attendance_verifications', [
            'employee_id' => $employee->id,
            'verification_type' => 'face',
        ]);
    }
}
