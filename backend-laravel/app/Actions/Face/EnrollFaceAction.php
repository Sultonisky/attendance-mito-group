<?php

namespace App\Actions\Face;

use App\DTO\EnrollResult;
use App\Enums\FastApiStatus;
use App\Enums\VerificationStatus;
use App\Enums\VerificationType;
use App\Models\AttendanceVerification;
use App\Models\Employee;
use App\Models\EmployeeFaceEmbedding;
use App\Models\EmployeeFaceProfile;
use App\Services\Integration\FastApiService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Enroll an employee's face via the FastAPI AI service.
 *
 * Laravel remains the business authority:
 * - Validates that the employee exists and is enrolled.
 * - Persists the opaque embedding_reference (never the raw embedding).
 * - Records an audit trail via AttendanceVerification.
 *
 * FastAPI only returns AI facts (face detected, quality, model version).
 */
class EnrollFaceAction
{
    public function __construct(
        protected FastApiService $fastApi,
    ) {}

    /**
     * Execute face enrollment for the given employee.
     *
     * @param  string  $imagePath  Absolute path to the uploaded face image.
     * @return array{status: FastApiStatus, enrolled: bool, result: EnrollResult|null, error: string|null}
     */
    public function execute(Employee $employee, string $imagePath): array
    {
        $response = $this->fastApi->enroll(
            $employee->employee_code,
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

        if (! $result->enrolled || ! $result->faceDetected) {
            return [
                'status' => FastApiStatus::Unavailable,
                'enrolled' => false,
                'result' => $result,
                'error' => 'Face was not detected during enrollment.',
            ];
        }

        DB::transaction(function () use ($employee, $result) {
            $profile = EmployeeFaceProfile::updateOrCreate(
                ['employee_id' => $employee->id],
                [
                    'model_version' => $result->modelVersion,
                    'status' => 'active',
                    'metadata' => [
                        'quality_score' => $result->qualityScore,
                    ],
                ],
            );

            EmployeeFaceEmbedding::create([
                'employee_face_profile_id' => $profile->id,
                'model_version' => $result->modelVersion,
                'embedding_reference' => $result->embeddingReference,
                'provider' => 'fastapi',
                'status' => 'active',
            ]);

            AttendanceVerification::create([
                'employee_id' => $employee->id,
                'verification_type' => VerificationType::Face->value,
                'status' => VerificationStatus::Passed->value,
                'details' => [
                    'action' => 'enroll',
                    'model_version' => $result->modelVersion,
                    'embedding_reference' => $result->embeddingReference,
                    'face_detected' => $result->faceDetected,
                    'quality_score' => $result->qualityScore,
                ],
                'verified_at' => now(),
            ]);
        });

        return [
            'status' => FastApiStatus::Available,
            'enrolled' => true,
            'result' => $result,
            'error' => null,
        ];
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
