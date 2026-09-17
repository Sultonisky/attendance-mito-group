<?php

namespace Tests\Unit\Actions\Face;

use App\Actions\Face\EnrollFaceAction;
use App\Enums\FastApiStatus;
use App\Models\Employee;
use App\Policies\EnrollmentPolicy;
use App\Services\Integration\FastApiService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class EnrollFaceActionTest extends TestCase
{
    use RefreshDatabase;

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

        return $fastApi;
    }

    public function test_same_idempotency_key_does_not_create_duplicate_embeddings(): void
    {
        $employee = $this->makeEmployee();
        $fastApi = $this->mockFastApiSuccess('idempotent_ref');

        $action = new EnrollFaceAction($fastApi, new EnrollmentPolicy);

        $tempPath = sys_get_temp_dir().DIRECTORY_SEPARATOR.'fake_face.jpg';
        file_put_contents($tempPath, 'fake_image_data');

        $result1 = $action->execute($employee, $tempPath, 'same-key-twice');
        $result2 = $action->execute($employee, $tempPath, 'same-key-twice');

        $this->assertSame(FastApiStatus::Available, $result1['status']);
        $this->assertTrue($result1['enrolled']);
        $this->assertSame(FastApiStatus::Available, $result2['status']);
        $this->assertTrue($result2['enrolled']);

        $this->assertDatabaseCount('employee_face_embeddings', 1);
        $this->assertDatabaseCount('employee_face_profiles', 1);

        unlink($tempPath);
    }

    public function test_different_idempotency_keys_create_separate_embeddings(): void
    {
        $employee = $this->makeEmployee();
        $fastApi = Mockery::mock(FastApiService::class);
        $fastApi->shouldReceive('enroll')
            ->andReturn([
                'status' => FastApiStatus::Available,
                'data' => [
                    'enrolled' => true,
                    'model_version' => 'mito-face-v1',
                    'embedding_reference' => 'ref123',
                    'face_detected' => true,
                    'quality' => ['score' => 0.9],
                    'liveness' => ['label' => 'real', 'live_prob' => 0.96, 'probs' => ['real' => 0.96, 'print' => 0.02, 'replay' => 0.02]],
                    'processing_time_ms' => 22.0,
                ],
                'error' => null,
            ]);

        $action = new EnrollFaceAction($fastApi, new EnrollmentPolicy);

        $tempPath = sys_get_temp_dir().DIRECTORY_SEPARATOR.'fake_face.jpg';
        file_put_contents($tempPath, 'fake_image_data');

        $action->execute($employee, $tempPath, 'key-one');
        $action->execute($employee, $tempPath, 'key-two');

        $this->assertDatabaseCount('employee_face_embeddings', 2);
        $this->assertDatabaseCount('employee_face_profiles', 1);

        unlink($tempPath);
    }
}
