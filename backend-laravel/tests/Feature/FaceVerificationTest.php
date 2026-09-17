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
use Illuminate\Support\Facades\DB;
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
 * - Never exposes raw embeddings or employee identity to FastAPI.
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
                'model_version' => 'mito-face-v1',
                'embedding_reference' => 'abc123testref',
                'face_detected' => true,
                'quality' => ['score' => 0.95],
                'liveness' => ['passed' => true],
                'processing_time_ms' => 42.5,
            ], 200),
        ]);

        $response = $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/face/enroll', [
                'employee_id' => $employee->id,
                'image' => $this->test_image(),
            ]);

        $response->assertOk();
        $response->assertJsonPath('data.enrolled', true);
        $response->assertJsonPath('data.model_version', 'mito-face-v1');
        $response->assertJsonPath('data.embedding_reference', 'abc123testref');
        $response->assertJsonPath('data.face_detected', true);
        $response->assertJsonPath('data.quality', ['score' => 0.95]);
        $response->assertJsonPath('data.liveness', ['passed' => true]);
        $response->assertJsonPath('data.processing_time_ms', 42.5);
        $response->assertJsonMissing(['data.employee_id']);
        $response->assertJsonMissing(['data.employee_code']);
    }

    /**
     * AI-3.4 persists face profile, embedding, and enrollment audit on acceptance.
     */
    public function test_enroll_persists_face_profile_and_verification(): void
    {
        $user = $this->adminUser();
        $employee = Employee::factory()->create();

        Http::fake([
            '*/face/enroll' => Http::response([
                'enrolled' => true,
                'model_version' => 'mito-face-v1',
                'embedding_reference' => 'persisted_ref_123',
                'face_detected' => true,
                'quality' => ['score' => 0.92],
                'liveness' => ['label' => 'real', 'live_prob' => 0.95, 'probs' => ['real' => 0.95, 'print' => 0.02, 'replay' => 0.03]],
                'processing_time_ms' => 30.0,
            ], 200),
        ]);

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/face/enroll', [
                'employee_id' => $employee->id,
                'image' => $this->test_image(),
            ])->assertOk();

        $profile = EmployeeFaceProfile::where('employee_id', $employee->id)->first();
        $this->assertNotNull($profile);
        $this->assertSame('mito-face-v1', $profile->model_version);
        $this->assertSame('active', $profile->status);
        $this->assertSame(true, $profile->metadata['face_detected']);
        $this->assertSame('real', $profile->metadata['liveness']['label']);

        $embedding = EmployeeFaceEmbedding::where('employee_face_profile_id', $profile->id)->first();
        $this->assertNotNull($embedding);
        $this->assertSame('persisted_ref_123', $embedding->embedding_reference);
        $this->assertSame('fastapi', $embedding->provider);
        $this->assertNotNull($embedding->idempotency_key);

        $verification = AttendanceVerification::where('employee_id', $employee->id)
            ->where('verification_type', 'face')
            ->first();
        $this->assertNotNull($verification);
        $this->assertSame('passed', $verification->status);
        $this->assertSame('mito-face-v1', $verification->model_version);
        $this->assertSame('enroll', $verification->details['action']);
        $this->assertSame('persisted_ref_123', $verification->details['embedding_reference']);
        $this->assertSame('real', $verification->details['liveness']['label']);
    }

    /**
     * Enroll failure when no face is detected.
     */
    public function test_enroll_fails_when_no_face_detected(): void
    {
        $user = $this->adminUser();
        $employee = Employee::factory()->create();

        Http::fake([
            '*/face/enroll' => Http::response([
                'enrolled' => true,
                'model_version' => 'mito-face-v1',
                'embedding_reference' => '',
                'face_detected' => false,
                'quality' => ['score' => 0.1],
                'liveness' => [],
                'processing_time_ms' => 15.0,
            ], 200),
        ]);

        $response = $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/face/enroll', [
                'employee_id' => $employee->id,
                'image' => $this->test_image(),
            ]);

        $response->assertStatus(422);
        $response->assertJsonPath('success', false);
        $response->assertJsonPath('error', 'No face was detected during enrollment.');
    }

    /**
     * Laravel generates a UUIDv4 idempotency key and sends it to FastAPI
     * without employee identity.
     */
    public function test_enroll_sends_uuidv4_idempotency_key_without_employee_identity(): void
    {
        $user = $this->adminUser();
        $employee = Employee::factory()->create();

        /** @var \Illuminate\Http\Client\Request|null $capturedRequest */
        $capturedRequest = null;

        Http::fake([
            '*/face/enroll' => function ($request) use (&$capturedRequest) {
                $capturedRequest = $request;

                return Http::response([
                    'enrolled' => true,
                    'model_version' => 'mito-face-v1',
                    'embedding_reference' => 'ref123',
                    'face_detected' => true,
                    'quality' => ['score' => 0.9],
                    'liveness' => ['passed' => true],
                    'processing_time_ms' => 25.0,
                ], 200);
            },
        ]);

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/face/enroll', [
                'employee_id' => $employee->id,
                'image' => $this->test_image(),
            ])->assertOk();

        $this->assertNotNull($capturedRequest);
        $body = $capturedRequest->body();

        $this->assertMatchesRegularExpression(
            '/name="idempotency_key"\s*'."\r?\n\r?\n".'([0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12})/i',
            $body,
            'Idempotency key must be a valid UUIDv4 in the multipart body.'
        );

        $this->assertStringNotContainsString('employee_id', $body);
        $this->assertStringNotContainsString('employee_code', $body);
        $this->assertStringNotContainsString('employee_name', $body);
        $this->assertStringNotContainsString('user_id', $body);
    }

    /**
     * FastAPI 400 is mapped to Laravel 422.
     */
    public function test_enroll_maps_fastapi_400_to_422(): void
    {
        $user = $this->adminUser();
        $employee = Employee::factory()->create();

        Http::fake([
            '*/face/enroll' => Http::response(['detail' => 'Bad image'], 400),
        ]);

        $response = $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/face/enroll', [
                'employee_id' => $employee->id,
                'image' => $this->test_image(),
            ]);

        $response->assertStatus(422);
        $response->assertJsonPath('success', false);
        $this->assertStringNotContainsString('Bad image', (string) $response->getContent());
    }

    /**
     * FastAPI 401 is mapped to Laravel 422 without exposing API key details.
     */
    public function test_enroll_maps_fastapi_401_without_leaking_api_key(): void
    {
        $user = $this->adminUser();
        $employee = Employee::factory()->create();

        Http::fake([
            '*/face/enroll' => Http::response(['detail' => 'Invalid API key'], 401),
        ]);

        $response = $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/face/enroll', [
                'employee_id' => $employee->id,
                'image' => $this->test_image(),
            ]);

        $response->assertStatus(422);
        $response->assertJsonPath('success', false);
        $this->assertStringNotContainsString('API key', (string) $response->getContent());
        $this->assertStringNotContainsString('X-API-Key', (string) $response->getContent());
    }

    /**
     * FastAPI 409 is mapped to Laravel 409.
     */
    public function test_enroll_maps_fastapi_409_to_409(): void
    {
        $user = $this->adminUser();
        $employee = Employee::factory()->create();

        Http::fake([
            '*/face/enroll' => Http::response(['detail' => 'Duplicate enrollment'], 409),
        ]);

        $response = $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/face/enroll', [
                'employee_id' => $employee->id,
                'image' => $this->test_image(),
            ]);

        $response->assertStatus(409);
        $response->assertJsonPath('success', false);
        $this->assertStringNotContainsString('Duplicate enrollment', (string) $response->getContent());
    }

    /**
     * FastAPI 422 is mapped to Laravel 422.
     */
    public function test_enroll_maps_fastapi_422_to_422(): void
    {
        $user = $this->adminUser();
        $employee = Employee::factory()->create();

        Http::fake([
            '*/face/enroll' => Http::response(['detail' => 'Face too blurry'], 422),
        ]);

        $response = $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/face/enroll', [
                'employee_id' => $employee->id,
                'image' => $this->test_image(),
            ]);

        $response->assertStatus(422);
        $response->assertJsonPath('success', false);
        $this->assertStringNotContainsString('Face too blurry', (string) $response->getContent());
    }

    /**
     * FastAPI 503 is mapped to Laravel 503.
     */
    public function test_enroll_maps_fastapi_503_to_503(): void
    {
        $user = $this->adminUser();
        $employee = Employee::factory()->create();

        Http::fake([
            '*/face/enroll' => Http::response(['detail' => 'Service overloaded'], 503),
        ]);

        $response = $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/face/enroll', [
                'employee_id' => $employee->id,
                'image' => $this->test_image(),
            ]);

        $response->assertStatus(503);
        $response->assertJsonPath('success', false);
        $this->assertStringNotContainsString('Service overloaded', (string) $response->getContent());
    }

    /**
     * Enrollment is rejected when liveness indicates a spoofed/print/replay result.
     */
    public function test_enroll_rejects_spoofed_liveness(): void
    {
        $user = $this->adminUser();
        $employee = Employee::factory()->create();

        Http::fake([
            '*/face/enroll' => Http::response([
                'enrolled' => true,
                'model_version' => 'mito-face-v1',
                'embedding_reference' => 'ref123',
                'face_detected' => true,
                'quality' => ['score' => 0.9],
                'liveness' => ['label' => 'print', 'live_prob' => 0.05, 'probs' => ['print' => 0.95, 'real' => 0.05]],
                'processing_time_ms' => 25.0,
            ], 200),
        ]);

        $response = $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/face/enroll', [
                'employee_id' => $employee->id,
                'image' => $this->test_image(),
            ]);

        $response->assertStatus(422);
        $response->assertJsonPath('success', false);
        $response->assertJsonPath('error', 'Spoofed liveness detected; enrollment rejected.');

        $this->assertDatabaseMissing('employee_face_profiles', [
            'employee_id' => $employee->id,
        ]);

        $this->assertDatabaseMissing('employee_face_embeddings', [
            'embedding_reference' => 'ref123',
        ]);
    }

    /**
     * Enrollment accepts real liveness and persists the enrollment.
     */
    public function test_enroll_accepts_real_liveness_and_persists(): void
    {
        $user = $this->adminUser();
        $employee = Employee::factory()->create();

        Http::fake([
            '*/face/enroll' => Http::response([
                'enrolled' => true,
                'model_version' => 'mito-face-v1',
                'embedding_reference' => 'ref456',
                'face_detected' => true,
                'quality' => ['score' => 0.88],
                'liveness' => ['label' => 'real', 'live_prob' => 0.97, 'probs' => ['real' => 0.97, 'print' => 0.02, 'replay' => 0.01]],
                'processing_time_ms' => 28.0,
            ], 200),
        ]);

        $response = $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/face/enroll', [
                'employee_id' => $employee->id,
                'image' => $this->test_image(),
            ]);

        $response->assertOk();
        $response->assertJsonPath('data.enrolled', true);
        $response->assertJsonPath('data.embedding_reference', 'ref456');

        $this->assertDatabaseHas('employee_face_profiles', [
            'employee_id' => $employee->id,
            'model_version' => 'mito-face-v1',
            'status' => 'active',
        ]);

        $this->assertDatabaseHas('employee_face_embeddings', [
            'embedding_reference' => 'ref456',
            'provider' => 'fastapi',
            'status' => 'active',
        ]);
    }

    /**
     * Enrollment persists a non-null idempotency key on the embedding record.
     */
    public function test_enroll_persists_idempotency_key(): void
    {
        $user = $this->adminUser();
        $employee = Employee::factory()->create();

        Http::fake([
            '*/face/enroll' => Http::response([
                'enrolled' => true,
                'model_version' => 'mito-face-v1',
                'embedding_reference' => 'idempotent_ref',
                'face_detected' => true,
                'quality' => ['score' => 0.9],
                'liveness' => ['label' => 'real', 'live_prob' => 0.96, 'probs' => ['real' => 0.96, 'print' => 0.02, 'replay' => 0.02]],
                'processing_time_ms' => 22.0,
            ], 200),
        ]);

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/face/enroll', [
                'employee_id' => $employee->id,
                'image' => $this->test_image(),
            ])->assertOk();

        $embedding = EmployeeFaceEmbedding::where('embedding_reference', 'idempotent_ref')->first();
        $this->assertNotNull($embedding);
        $this->assertNotNull($embedding->idempotency_key);
        $this->assertMatchesRegularExpression(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i',
            $embedding->idempotency_key
        );
    }

    /**
     * Transaction rollback: if embedding persistence fails, no profile remains.
     */
    public function test_enroll_transaction_rollback_on_persistence_failure(): void
    {
        $user = $this->adminUser();
        $employee = Employee::factory()->create();

        Http::fake([
            '*/face/enroll' => Http::response([
                'enrolled' => true,
                'model_version' => 'mito-face-v1',
                'embedding_reference' => 'rollback_ref',
                'face_detected' => true,
                'quality' => ['score' => 0.9],
                'liveness' => ['label' => 'real', 'live_prob' => 0.96, 'probs' => ['real' => 0.96, 'print' => 0.02, 'replay' => 0.02]],
                'processing_time_ms' => 22.0,
            ], 200),
        ]);

        DB::listen(function ($query) {
            if (str_contains($query->sql, 'employee_face_embeddings') && str_contains($query->sql, 'insert')) {
                throw new \RuntimeException('Simulated persistence failure.');
            }
        });

        $response = $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/face/enroll', [
                'employee_id' => $employee->id,
                'image' => $this->test_image(),
            ]);

        $response->assertStatus(422);

        $this->assertDatabaseMissing('employee_face_profiles', [
            'employee_id' => $employee->id,
        ]);

        $this->assertDatabaseMissing('employee_face_embeddings', [
            'embedding_reference' => 'rollback_ref',
        ]);
    }

    /**
     * Re-enrollment deactivates the previous ACTIVE embedding and activates the new one.
     */
    public function test_enroll_re_enrollment_deactivates_previous_active_embedding(): void
    {
        $user = $this->adminUser();
        $employee = Employee::factory()->create();

        $profile = EmployeeFaceProfile::create([
            'employee_id' => $employee->id,
            'model_version' => 'mito-face-v1',
            'status' => 'active',
            'metadata' => [],
        ]);

        $oldEmbedding = EmployeeFaceEmbedding::create([
            'employee_face_profile_id' => $profile->id,
            'model_version' => 'mito-face-v1',
            'embedding_reference' => 'old_ref',
            'provider' => 'fastapi',
            'status' => 'active',
            'idempotency_key' => 'old-key',
        ]);

        Http::fake([
            '*/face/enroll' => Http::response([
                'enrolled' => true,
                'model_version' => 'mito-face-v2',
                'embedding_reference' => 'new_ref',
                'face_detected' => true,
                'quality' => ['score' => 0.9],
                'liveness' => ['label' => 'real', 'live_prob' => 0.96, 'probs' => ['real' => 0.96, 'print' => 0.02, 'replay' => 0.02]],
                'processing_time_ms' => 22.0,
            ], 200),
        ]);

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/face/enroll', [
                'employee_id' => $employee->id,
                'image' => $this->test_image(),
            ])->assertOk();

        $oldEmbedding->refresh();
        $newEmbedding = EmployeeFaceEmbedding::where('embedding_reference', 'new_ref')->first();

        $this->assertNotNull($newEmbedding);
        $this->assertSame('active', $newEmbedding->status);
        $this->assertSame('mito-face-v2', $newEmbedding->model_version);

        $this->assertSame('inactive', $oldEmbedding->status);
        $this->assertDatabaseCount('employee_face_embeddings', 2);

        $profile->refresh();
        $this->assertSame('mito-face-v2', $profile->model_version);
    }

    /**
     * Failed FastAPI enrollment preserves the existing ACTIVE embedding.
     */
    public function test_enroll_failed_fastapi_preserves_active_embedding(): void
    {
        $user = $this->adminUser();
        $employee = Employee::factory()->create();

        $profile = EmployeeFaceProfile::create([
            'employee_id' => $employee->id,
            'model_version' => 'mito-face-v1',
            'status' => 'active',
            'metadata' => [],
        ]);

        EmployeeFaceEmbedding::create([
            'employee_face_profile_id' => $profile->id,
            'model_version' => 'mito-face-v1',
            'embedding_reference' => 'old_ref',
            'provider' => 'fastapi',
            'status' => 'active',
            'idempotency_key' => 'old-key',
        ]);

        Http::fake([
            '*/face/enroll' => Http::response(['detail' => 'AI model unavailable.'], 503),
        ]);

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/face/enroll', [
                'employee_id' => $employee->id,
                'image' => $this->test_image(),
            ])->assertStatus(503);

        $this->assertDatabaseCount('employee_face_embeddings', 1);
        $this->assertDatabaseHas('employee_face_embeddings', [
            'embedding_reference' => 'old_ref',
            'status' => 'active',
        ]);
    }

    /**
     * Policy rejection preserves the existing ACTIVE embedding.
     */
    public function test_enroll_policy_rejection_preserves_active_embedding(): void
    {
        $user = $this->adminUser();
        $employee = Employee::factory()->create();

        $profile = EmployeeFaceProfile::create([
            'employee_id' => $employee->id,
            'model_version' => 'mito-face-v1',
            'status' => 'active',
            'metadata' => [],
        ]);

        EmployeeFaceEmbedding::create([
            'employee_face_profile_id' => $profile->id,
            'model_version' => 'mito-face-v1',
            'embedding_reference' => 'old_ref',
            'provider' => 'fastapi',
            'status' => 'active',
            'idempotency_key' => 'old-key',
        ]);

        Http::fake([
            '*/face/enroll' => Http::response([
                'enrolled' => true,
                'model_version' => 'mito-face-v1',
                'embedding_reference' => 'new_ref',
                'face_detected' => true,
                'quality' => ['score' => 0.9],
                'liveness' => ['label' => 'print', 'live_prob' => 0.05, 'probs' => ['print' => 0.95, 'real' => 0.05]],
                'processing_time_ms' => 22.0,
            ], 200),
        ]);

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/face/enroll', [
                'employee_id' => $employee->id,
                'image' => $this->test_image(),
            ])->assertStatus(422);

        $this->assertDatabaseCount('employee_face_embeddings', 1);
        $this->assertDatabaseHas('employee_face_embeddings', [
            'embedding_reference' => 'old_ref',
            'status' => 'active',
        ]);
    }

    /**
     * Re-enrollment transaction failure preserves old embedding and compensates FastAPI.
     */
    public function test_enroll_transaction_failure_compensates_fastapi(): void
    {
        $user = $this->adminUser();
        $employee = Employee::factory()->create();

        $profile = EmployeeFaceProfile::create([
            'employee_id' => $employee->id,
            'model_version' => 'mito-face-v1',
            'status' => 'active',
            'metadata' => [],
        ]);

        EmployeeFaceEmbedding::create([
            'employee_face_profile_id' => $profile->id,
            'model_version' => 'mito-face-v1',
            'embedding_reference' => 'old_ref',
            'provider' => 'fastapi',
            'status' => 'active',
            'idempotency_key' => 'old-key',
        ]);

        Http::fake([
            '*/face/enroll' => Http::response([
                'enrolled' => true,
                'model_version' => 'mito-face-v2',
                'embedding_reference' => 'new_ref',
                'face_detected' => true,
                'quality' => ['score' => 0.9],
                'liveness' => ['label' => 'real', 'live_prob' => 0.96, 'probs' => ['real' => 0.96, 'print' => 0.02, 'replay' => 0.02]],
                'processing_time_ms' => 22.0,
            ], 200),
            '*/face/embeddings/new_ref' => Http::response(null, 204),
        ]);

        DB::listen(function ($query) {
            if (str_contains($query->sql, 'employee_face_embeddings') && str_contains($query->sql, 'insert')) {
                throw new \RuntimeException('Simulated persistence failure.');
            }
        });

        $response = $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/face/enroll', [
                'employee_id' => $employee->id,
                'image' => $this->test_image(),
            ]);

        $response->assertStatus(422);

        $this->assertDatabaseHas('employee_face_embeddings', [
            'embedding_reference' => 'old_ref',
            'status' => 'active',
        ]);

        $this->assertDatabaseMissing('employee_face_embeddings', [
            'embedding_reference' => 'new_ref',
        ]);
    }

    /**
     * Compensation failure does not produce a successful enrollment response.
     */
    public function test_enroll_compensation_failure_does_not_report_success(): void
    {
        $user = $this->adminUser();
        $employee = Employee::factory()->create();

        Http::fake([
            '*/face/enroll' => Http::response([
                'enrolled' => true,
                'model_version' => 'mito-face-v1',
                'embedding_reference' => 'orphan_ref',
                'face_detected' => true,
                'quality' => ['score' => 0.9],
                'liveness' => ['label' => 'real', 'live_prob' => 0.96, 'probs' => ['real' => 0.96, 'print' => 0.02, 'replay' => 0.02]],
                'processing_time_ms' => 22.0,
            ], 200),
            '*/face/embeddings/orphan_ref' => Http::response(['detail' => 'Internal server error.'], 500),
        ]);

        DB::listen(function ($query) {
            if (str_contains($query->sql, 'employee_face_embeddings') && str_contains($query->sql, 'insert')) {
                throw new \RuntimeException('Simulated persistence failure.');
            }
        });

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
