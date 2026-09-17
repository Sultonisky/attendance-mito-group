<?php

namespace Tests\Integration\PostgreSQL;

use App\Actions\Face\EnrollFaceAction;
use App\Enums\FastApiStatus;
use App\Models\Employee;
use App\Models\EmployeeFaceEmbedding;
use App\Models\EmployeeFaceProfile;
use App\Policies\EnrollmentPolicy;
use App\Services\Integration\FastApiService;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Mockery;
use Tests\TestCase;
use Tests\Traits\RefreshDatabasePostgres;

/**
 * Face enrollment PostgreSQL integration tests.
 *
 * These tests verify database-level concurrency guarantees that depend on
 * PostgreSQL locking and partial unique index semantics. They must run
 * against a real PostgreSQL instance and use RefreshDatabasePostgres.
 *
 * SQLite cannot faithfully reproduce the locking behavior validated here.
 */
class FaceEnrollmentPostgresTest extends TestCase
{
    use RefreshDatabasePostgres;

    private function makeEmployee(array $overrides = []): Employee
    {
        return Employee::factory()->create($overrides);
    }

    private function mockFastApiSuccess(string $embeddingReference = 'ref123'): FastApiService
    {
        $fastApi = Mockery::mock(FastApiService::class);
        $fastApi->shouldReceive('enroll')
            ->andReturn([
                'status' => FastApiStatus::Available,
                'data' => [
                    'enrolled' => true,
                    'model_version' => 'mito-face-v1',
                    'embedding_reference' => $embeddingReference,
                    'face_detected' => true,
                    'quality' => ['score' => 0.9],
                    'liveness' => ['label' => 'real', 'live_prob' => 0.96, 'probs' => ['real' => 0.96, 'print' => 0.02, 'replay' => 0.02]],
                    'processing_time_ms' => 22.0,
                ],
                'error' => null,
            ]);

        $fastApi->shouldReceive('deleteEmbedding')
            ->andReturn([
                'status' => FastApiStatus::Available,
                'deleted' => true,
                'error' => null,
            ]);

        return $fastApi;
    }

    public function test_partial_unique_index_prevents_multiple_active_embeddings(): void
    {
        $employee = $this->makeEmployee();
        $profile = EmployeeFaceProfile::create([
            'employee_id' => $employee->id,
            'model_version' => 'mito-face-v1',
            'status' => 'active',
            'metadata' => [],
        ]);

        EmployeeFaceEmbedding::create([
            'employee_face_profile_id' => $profile->id,
            'model_version' => 'mito-face-v1',
            'embedding_reference' => 'ref1',
            'provider' => 'fastapi',
            'status' => 'active',
            'idempotency_key' => 'key1',
        ]);

        $this->expectException(UniqueConstraintViolationException::class);

        EmployeeFaceEmbedding::create([
            'employee_face_profile_id' => $profile->id,
            'model_version' => 'mito-face-v1',
            'embedding_reference' => 'ref2',
            'provider' => 'fastapi',
            'status' => 'active',
            'idempotency_key' => 'key2',
        ]);
    }

    public function test_re_enrollment_deactivates_old_and_activates_new(): void
    {
        $employee = $this->makeEmployee();
        $fastApi = $this->mockFastApiSuccess('new_ref');
        $action = new EnrollFaceAction($fastApi, new EnrollmentPolicy);

        $tempPath = sys_get_temp_dir().DIRECTORY_SEPARATOR.'face.jpg';
        file_put_contents($tempPath, 'fake_image_data');

        // First enrollment
        $result1 = $action->execute($employee, $tempPath, 'key-1');
        $this->assertSame(FastApiStatus::Available, $result1['status']);
        $this->assertTrue($result1['enrolled']);

        $profile = EmployeeFaceProfile::where('employee_id', $employee->id)->first();
        $this->assertSame(1, EmployeeFaceEmbedding::where('employee_face_profile_id', $profile->id)
            ->where('status', 'active')
            ->count());

        // Re-enrollment
        $result2 = $action->execute($employee, $tempPath, 'key-2');
        $this->assertSame(FastApiStatus::Available, $result2['status']);
        $this->assertTrue($result2['enrolled']);
        $this->assertSame('new_ref', $result2['result']->embeddingReference);

        $activeCount = EmployeeFaceEmbedding::where('employee_face_profile_id', $profile->id)
            ->where('status', 'active')
            ->count();
        $this->assertSame(1, $activeCount, 'Exactly one active embedding must exist after re-enrollment.');

        $activeEmbedding = EmployeeFaceEmbedding::where('employee_face_profile_id', $profile->id)
            ->where('status', 'active')
            ->first();
        $this->assertSame('new_ref', $activeEmbedding->embedding_reference);

        unlink($tempPath);
    }

    public function test_profile_lock_prevents_duplicate_active_embeddings(): void
    {
        $employee = $this->makeEmployee();
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

        // Simulate the critical section: lock profile, deactivate old, insert new.
        DB::transaction(function () use ($profile) {
            EmployeeFaceProfile::where('id', $profile->id)->lockForUpdate()->first();

            EmployeeFaceEmbedding::where('employee_face_profile_id', $profile->id)
                ->where('status', 'active')
                ->update(['status' => 'inactive']);

            EmployeeFaceEmbedding::create([
                'employee_face_profile_id' => $profile->id,
                'model_version' => 'mito-face-v1',
                'embedding_reference' => 'new_ref',
                'provider' => 'fastapi',
                'status' => 'active',
                'idempotency_key' => 'new-key',
            ]);
        });

        $activeCount = EmployeeFaceEmbedding::where('employee_face_profile_id', $profile->id)
            ->where('status', 'active')
            ->count();
        $this->assertSame(1, $activeCount, 'Exactly one active embedding must exist after locked re-enrollment.');
    }
}
