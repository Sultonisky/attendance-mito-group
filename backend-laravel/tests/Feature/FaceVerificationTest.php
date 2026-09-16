<?php

namespace Tests\Feature;

use App\Models\AttendanceVerification;
use App\Models\Employee;
use App\Models\EmployeeFaceEmbedding;
use App\Models\EmployeeFaceProfile;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Testing\File;
use Illuminate\Support\Facades\Http;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Feature tests for the face verification endpoints.
 *
 * FastAPI is an internal AI/CV service that returns facts only. These tests
 * mock the Laravel FastApiService HTTP calls to verify that Laravel correctly:
 *
 * - Authenticates and authorizes requests.
 * - Validates input.
 * - Calls FastAPI with the correct API key.
 * - Evaluates FastAPI facts (verified, liveness, confidence) into a final
 *   business decision.
 * - Persists the opaque embedding_reference (never raw embeddings).
 * - Never treats an AI service failure as a successful verification.
 */
class FaceVerificationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    private function adminUser(): User
    {
        $user = User::factory()->create();
        $role = Role::findByName('ADMIN');
        $role->syncPermissions(Permission::all());

        $user->assignRole('ADMIN');

        return $user;
    }

    private function regularUser(): User
    {
        return User::factory()->create();
    }

    private function test_image(string $name = 'face.jpg'): File
    {
        return File::image($name, 200, 200);
    }

    /**
     * Unauthenticated enroll requests receive 401.
     */
    public function test_enroll_requires_authentication(): void
    {
        $this->postJson('/api/v1/face/enroll', [])
            ->assertUnauthorized();
    }

    /**
     * Unauthenticated verify requests receive 401.
     */
    public function test_verify_requires_authentication(): void
    {
        $this->postJson('/api/v1/face/verify', [])
            ->assertUnauthorized();
    }

    /**
     * Enroll validation errors return 422 for missing data.
     */
    public function test_enroll_returns_validation_error_for_missing_data(): void
    {
        $this->actingAs($this->adminUser(), 'sanctum')
            ->postJson('/api/v1/face/enroll', [])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['employee_id', 'image']);
    }

    /**
     * Verify validation errors return 422 for missing data.
     */
    public function test_verify_returns_validation_error_for_missing_data(): void
    {
        $this->actingAs($this->adminUser(), 'sanctum')
            ->postJson('/api/v1/face/verify', [])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['employee_id', 'image']);
    }

    /**
     * Enroll is denied to users without the employees.manage-faces permission.
     */
    public function test_enroll_denied_without_permission(): void
    {
        $user = $this->regularUser();
        $employee = Employee::factory()->create();

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/face/enroll', [
                'employee_id' => $employee->id,
                'image' => $this->test_image(),
            ])->assertForbidden();
    }

    /**
     * SUPER_ADMIN can enroll even without explicit permissions.
     */
    public function test_enroll_allowed_for_super_admin(): void
    {
        $user = User::factory()->create();
        $user->assignRole('SUPER_ADMIN');
        $employee = Employee::factory()->create();

        Http::fake([
            '*/face/enroll' => Http::response([
                'enrolled' => true,
                'model_version' => 'face-dev-v1',
                'embedding_reference' => 'abc123testref',
                'face_detected' => true,
                'quality_score' => 0.95,
            ], 200),
        ]);

        $response = $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/face/enroll', [
                'employee_id' => $employee->id,
                'image' => $this->test_image(),
            ]);

        $response->assertOk();
        $response->assertJsonPath('data.employee_code', $employee->employee_code);
        $response->assertJsonPath('data.model_version', 'face-dev-v1');
        $response->assertJsonPath('data.embedding_reference', 'abc123testref');
        $response->assertJsonPath('data.face_detected', true);
    }

    /**
     * Enroll persists the embedding reference and a verification record.
     */
    public function test_enroll_persists_face_profile_and_verification(): void
    {
        $user = $this->adminUser();
        $employee = Employee::factory()->create();

        Http::fake([
            '*/face/enroll' => Http::response([
                'enrolled' => true,
                'model_version' => 'face-dev-v1',
                'embedding_reference' => 'persisted_ref_123',
                'face_detected' => true,
                'quality_score' => 0.92,
            ], 200),
        ]);

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/face/enroll', [
                'employee_id' => $employee->id,
                'image' => $this->test_image(),
            ])->assertOk();

        $profile = EmployeeFaceProfile::where('employee_id', $employee->id)->first();
        $this->assertNotNull($profile);
        $this->assertSame('face-dev-v1', $profile->model_version);
        $this->assertSame('active', $profile->status);

        $embedding = EmployeeFaceEmbedding::where('employee_face_profile_id', $profile->id)->first();
        $this->assertNotNull($embedding);
        $this->assertSame('persisted_ref_123', $embedding->embedding_reference);
        $this->assertSame('fastapi', $embedding->provider);

        $verification = AttendanceVerification::where('employee_id', $employee->id)
            ->where('verification_type', 'face')
            ->first();
        $this->assertNotNull($verification);
        $this->assertSame('passed', $verification->status);
        $this->assertSame('enroll', $verification->details['action']);
    }

    /**
     * Enroll failure when FastAPI reports no face detected.
     */
    public function test_enroll_fails_when_no_face_detected(): void
    {
        $user = $this->adminUser();
        $employee = Employee::factory()->create();

        Http::fake([
            '*/face/enroll' => Http::response([
                'enrolled' => true,
                'model_version' => 'face-dev-v1',
                'embedding_reference' => '',
                'face_detected' => false,
                'quality_score' => 0.1,
            ], 200),
        ]);

        $response = $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/face/enroll', [
                'employee_id' => $employee->id,
                'image' => $this->test_image(),
            ]);

        $response->assertStatus(422);
        $response->assertJsonPath('success', false);
    }

    /**
     * Verify returns the AI facts along with Laravel's final decision.
     */
    public function test_verify_returns_ai_facts_and_decision(): void
    {
        $user = $this->adminUser();
        $employee = Employee::factory()->create();

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

        $response = $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/face/verify', [
                'employee_id' => $employee->id,
                'embedding_reference' => 'test_ref',
                'image' => $this->test_image(),
            ]);

        $response->assertOk();
        $response->assertJsonPath('success', true);
        $response->assertJsonPath('data.passed', true);
        $response->assertJsonPath('data.ai_facts.verified', true);
        $response->assertJsonPath('data.ai_facts.confidence', 0.94);
        $response->assertJsonPath('data.ai_facts.liveness', true);
        $response->assertJsonPath('data.ai_facts.face_detected', true);
    }

    /**
     * Verify passes when face is detected and verified.
     */
    public function test_verify_passes_with_face_detected_and_verified(): void
    {
        $user = $this->adminUser();
        $employee = Employee::factory()->create();

        Http::fake([
            '*/face/verify' => Http::response([
                'verified' => true,
                'confidence' => 0.85,
                'liveness' => false,
                'liveness_reason' => 'disabled',
                'face_detected' => true,
                'model_version' => 'face-dev-v1',
                'processing_time_ms' => 30,
                'quality_score' => 0.9,
            ], 200),
        ]);

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/face/verify', [
                'employee_id' => $employee->id,
                'image' => $this->test_image(),
            ])->assertOk()
            ->assertJsonPath('data.passed', false)
            ->assertJsonPath('data.ai_facts.verified', true);

        $verification = AttendanceVerification::where('employee_id', $employee->id)
            ->where('verification_type', 'face')
            ->first();
        $this->assertNotNull($verification);
        $this->assertSame('failed', $verification->status);
    }

    /**
     * Verify fails when liveness is required but fails.
     */
    public function test_verify_fails_when_liveness_required_but_failed(): void
    {
        $user = $this->adminUser();
        $employee = Employee::factory()->create();

        Http::fake([
            '*/face/verify' => Http::response([
                'verified' => true,
                'confidence' => 0.90,
                'liveness' => false,
                'liveness_reason' => 'no_blink_detected',
                'face_detected' => true,
                'model_version' => 'face-dev-v1',
                'processing_time_ms' => 50,
                'quality_score' => 0.92,
            ], 200),
        ]);

        $response = $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/face/verify', [
                'employee_id' => $employee->id,
                'image' => $this->test_image(),
            ]);

        $response->assertOk();
        $response->assertJsonPath('data.passed', false);
        $response->assertJsonPath('data.ai_facts.liveness', false);
    }

    /**
     * Verify fails when face is not detected.
     */
    public function test_verify_fails_when_face_not_detected(): void
    {
        $user = $this->adminUser();
        $employee = Employee::factory()->create();

        Http::fake([
            '*/face/verify' => Http::response([
                'verified' => false,
                'confidence' => 0.0,
                'liveness' => false,
                'liveness_reason' => null,
                'face_detected' => false,
                'model_version' => 'face-dev-v1',
                'processing_time_ms' => 20,
                'quality_score' => 0.1,
            ], 200),
        ]);

        $response = $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/face/verify', [
                'employee_id' => $employee->id,
                'image' => $this->test_image(),
            ]);

        $response->assertOk();
        $response->assertJsonPath('data.passed', false);
        $response->assertJsonPath('data.ai_facts.face_detected', false);
    }

    /**
     * Verify fails when confidence is below the AI threshold returned by FastAPI.
     */
    public function test_verify_fails_when_not_verified_by_ai(): void
    {
        $user = $this->adminUser();
        $employee = Employee::factory()->create();

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

        $response = $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/face/verify', [
                'employee_id' => $employee->id,
                'image' => $this->test_image(),
            ]);

        $response->assertOk();
        $response->assertJsonPath('data.passed', false);
    }

    /**
     * Verify returns 503 when the FastAPI service is unreachable.
     */
    public function test_verify_returns_503_when_ai_service_unavailable(): void
    {
        $user = $this->adminUser();
        $employee = Employee::factory()->create();

        Http::fake(function () {
            return Http::failedConnection();
        });

        $response = $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/face/verify', [
                'employee_id' => $employee->id,
                'image' => $this->test_image(),
            ]);

        $response->assertStatus(503);
        $response->assertJsonPath('success', false);
    }

    /**
     * Verify never treats a FastAPI failure as a successful verification.
     */
    public function test_verify_failure_never_passes(): void
    {
        $user = $this->adminUser();
        $employee = Employee::factory()->create();

        Http::fake([
            '*/face/verify' => Http::response([
                'detail' => 'Internal processing error',
            ], 500),
        ]);

        $response = $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/face/verify', [
                'employee_id' => $employee->id,
                'image' => $this->test_image(),
            ]);

        $response->assertStatus(503);
        $response->assertJsonPath('success', false);

        $verifications = AttendanceVerification::where('employee_id', $employee->id)->get();
        $this->assertCount(0, $verifications, 'No verification record should be persisted on AI service failure.');
    }

    /**
     * Verify persists an AttendanceVerification record on success.
     */
    public function test_verify_persists_verification_record(): void
    {
        $user = $this->adminUser();
        $employee = Employee::factory()->create();

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

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/face/verify', [
                'employee_id' => $employee->id,
                'image' => $this->test_image(),
            ])->assertOk();

        $verification = AttendanceVerification::where('employee_id', $employee->id)
            ->where('verification_type', 'face')
            ->first();
        $this->assertNotNull($verification);
        $this->assertSame('passed', $verification->status);
        $this->assertSame('face-dev-v1', $verification->details['model_version']);
        $this->assertSame(0.94, $verification->details['confidence']);
        $this->assertTrue($verification->details['liveness']);
    }

    /**
     * Verify requires face.verify permission.
     */
    public function test_verify_requires_face_verify_permission(): void
    {
        $user = $this->regularUser();
        $employee = Employee::factory()->create();

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

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/face/verify', [
                'employee_id' => $employee->id,
                'image' => $this->test_image(),
            ])->assertForbidden();
    }

    /**
     * Unauthorized user cannot verify another employee's face (IDOR prevention).
     */
    public function test_user_cannot_verify_another_employee_face(): void
    {
        $userA = $this->regularUser();
        $userB = $this->regularUser();
        $employeeB = Employee::factory()->create(['user_id' => $userB->id]);

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

        $this->actingAs($userA, 'sanctum')
            ->postJson('/api/v1/face/verify', [
                'employee_id' => $employeeB->id,
                'image' => $this->test_image(),
            ])->assertForbidden();
    }

    /**
     * SUPER_ADMIN can verify any employee.
     */
    public function test_super_admin_can_verify_any_employee(): void
    {
        $superAdmin = User::factory()->create();
        $superAdmin->assignRole('SUPER_ADMIN');
        $employee = Employee::factory()->create();

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

        $this->actingAs($superAdmin, 'sanctum')
            ->postJson('/api/v1/face/verify', [
                'employee_id' => $employee->id,
                'image' => $this->test_image(),
            ])->assertOk()
            ->assertJsonPath('success', true);
    }

    /**
     * Verify does not leak internal FastAPI error details to the client.
     */
    public function test_verify_does_not_leak_fastapi_internal_errors(): void
    {
        $user = $this->adminUser();
        $employee = Employee::factory()->create();

        Http::fake([
            '*/face/verify' => Http::response([
                'detail' => 'Traceback (most recent call last): File "/app/internal/server.py", line 42...',
            ], 500),
        ]);

        $response = $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/face/verify', [
                'employee_id' => $employee->id,
                'image' => $this->test_image(),
            ]);

        $response->assertStatus(503);
        $response->assertJsonPath('success', false);
        $response->assertJsonMissing(['data']);
        $this->assertStringNotContainsString('Traceback', (string) $response->getContent());
        $this->assertStringNotContainsString('/app/', (string) $response->getContent());
    }
}
