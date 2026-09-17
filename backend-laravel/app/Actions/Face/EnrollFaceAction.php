<?php

namespace App\Actions\Face;

use App\DTO\EnrollResult;
use App\Enums\FastApiStatus;
use App\Models\AttendanceVerification;
use App\Models\Employee;
use App\Models\EmployeeFaceEmbedding;
use App\Models\EmployeeFaceProfile;
use App\Policies\EnrollmentPolicy;
use App\Services\Integration\FastApiService;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Enroll an employee's face via the FastAPI AI service.
 *
 * Laravel remains the business authority:
 * - Validates that the employee exists.
 * - Generates a UUIDv4 idempotency key for the enrollment operation.
 * - Calls FastAPI with only the image and idempotency key.
 * - Applies EnrollmentPolicy to AI facts.
 * - Persists EmployeeFaceProfile, EmployeeFaceEmbedding, and enrollment audit.
 * - On re-enrollment, deactivates previous ACTIVE embedding(s).
 * - Compensates FastAPI storage if Laravel persistence fails after a successful
 *   FastAPI enrollment.
 *
 * FastAPI only returns AI facts (face detected, quality, model version).
 * Employee identity never leaves Laravel.
 */
class EnrollFaceAction
{
    public function __construct(
        protected FastApiService $fastApi,
        protected EnrollmentPolicy $policy,
    ) {}

    /**
     * Execute face enrollment for the given employee.
     *
     * @param  string|null  $idempotencyKey  Optional override for testing; generates UUIDv4 when null.
     * @return array{status: FastApiStatus, enrolled: bool, result: EnrollResult|null, error: string|null}
     */
    public function execute(Employee $employee, string $imagePath, ?string $idempotencyKey = null): array
    {
        $idempotencyKey ??= (string) Str::uuid();
        $newEmbeddingReference = null;

        $response = $this->fastApi->enroll(
            $idempotencyKey,
            $imagePath,
        );

        if ($response['status'] !== FastApiStatus::Available || $response['data'] === null) {
            return [
                'status' => $response['status'],
                'enrolled' => false,
                'result' => null,
                'error' => $response['error'],
            ];
        }

        $result = EnrollResult::fromArray($response['data']);
        $newEmbeddingReference = $result->embeddingReference;

        $policyResult = $this->policy->evaluate($result);

        if (! $policyResult->accepted) {
            $this->maybeCompensate($newEmbeddingReference);

            return [
                'status' => FastApiStatus::InvalidResponse,
                'enrolled' => false,
                'result' => $result,
                'error' => $policyResult->message ?? 'Enrollment rejected by policy.',
            ];
        }

        try {
            DB::transaction(function () use ($employee, $result, $idempotencyKey) {
                $profile = EmployeeFaceProfile::updateOrCreate(
                    ['employee_id' => $employee->id],
                    [
                        'model_version' => $result->modelVersion,
                        'status' => 'active',
                        'metadata' => $this->buildProfileMetadata($result),
                    ],
                );

                $newEmbedding = EmployeeFaceEmbedding::create([
                    'employee_face_profile_id' => $profile->id,
                    'model_version' => $result->modelVersion,
                    'embedding_reference' => $result->embeddingReference,
                    'provider' => 'fastapi',
                    'status' => 'active',
                    'idempotency_key' => $idempotencyKey,
                ]);

                EmployeeFaceEmbedding::where('employee_face_profile_id', $profile->id)
                    ->where('status', 'active')
                    ->where('id', '!=', $newEmbedding->id)
                    ->update(['status' => 'inactive']);

                AttendanceVerification::create([
                    'employee_id' => $employee->id,
                    'verification_type' => 'face',
                    'status' => 'passed',
                    'model_version' => $result->modelVersion,
                    'details' => [
                        'action' => 'enroll',
                        'embedding_reference' => $result->embeddingReference,
                        'face_detected' => $result->faceDetected,
                        'quality' => $result->quality,
                        'liveness' => $result->liveness,
                        'processing_time_ms' => $result->processingTimeMs,
                        'idempotency_key' => $idempotencyKey,
                    ],
                    'verified_at' => now(),
                ]);
            });
        } catch (UniqueConstraintViolationException $e) {
            $existing = EmployeeFaceEmbedding::where('idempotency_key', $idempotencyKey)->first();

            if ($existing !== null) {
                $result = $this->reconstructResultFromEmbedding($existing);

                return [
                    'status' => FastApiStatus::Available,
                    'enrolled' => true,
                    'result' => $result,
                    'error' => null,
                ];
            }

            $this->maybeCompensate($newEmbeddingReference);

            return [
                'status' => FastApiStatus::InvalidResponse,
                'enrolled' => false,
                'result' => null,
                'error' => 'Face enrollment could not be completed.',
            ];
        } catch (\Throwable $e) {
            $this->maybeCompensate($newEmbeddingReference);

            return [
                'status' => FastApiStatus::InvalidResponse,
                'enrolled' => false,
                'result' => null,
                'error' => 'Face enrollment could not be completed.',
            ];
        }

        return [
            'status' => FastApiStatus::Available,
            'enrolled' => true,
            'result' => $result,
            'error' => null,
        ];
    }

    /**
     * Compensate FastAPI storage by deleting a newly created embedding reference.
     */
    private function maybeCompensate(?string $embeddingReference): void
    {
        if ($embeddingReference === null || $embeddingReference === '') {
            return;
        }

        $deleteResponse = $this->fastApi->deleteEmbedding($embeddingReference);

        if ($deleteResponse['status'] !== FastApiStatus::Available || ! ($deleteResponse['deleted'] ?? false)) {
            Log::channel('stack')->warning('FastAPI compensation delete failed.', [
                'embedding_reference' => $embeddingReference,
                'error' => $deleteResponse['error'] ?? 'Unknown compensation failure.',
            ]);
        }
    }

    /**
     * Build the profile metadata payload from AI facts.
     *
     * @return array<string, mixed>
     */
    private function buildProfileMetadata(EnrollResult $result): array
    {
        return [
            'face_detected' => $result->faceDetected,
            'quality' => $result->quality,
            'liveness' => $result->liveness,
            'processing_time_ms' => $result->processingTimeMs,
        ];
    }

    /**
     * Reconstruct an EnrollResult from an existing embedding record.
     */
    private function reconstructResultFromEmbedding(EmployeeFaceEmbedding $embedding): EnrollResult
    {
        $profile = $embedding->faceProfile;
        $metadata = $profile->metadata ?? [];

        return new EnrollResult(
            enrolled: true,
            modelVersion: (string) ($embedding->model_version ?? ''),
            embeddingReference: (string) ($embedding->embedding_reference ?? ''),
            faceDetected: (bool) ($metadata['face_detected'] ?? false),
            quality: is_array($metadata['quality'] ?? null) ? $metadata['quality'] : [],
            liveness: is_array($metadata['liveness'] ?? null) ? $metadata['liveness'] : [],
            processingTimeMs: (float) ($metadata['processing_time_ms'] ?? 0.0),
        );
    }

    /**
     * Execute face enrollment from a temporary file path.
     */
    public function executeFromTempPath(Employee $employee, string $tempPath): array
    {
        return $this->execute($employee, $tempPath);
    }

    /**
     * Clean up a temporary image file.
     */
    public function cleanup(string $imagePath): void
    {
        if (Storage::exists($imagePath)) {
            Storage::delete($imagePath);
        }
    }
}
