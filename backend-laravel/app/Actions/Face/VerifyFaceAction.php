<?php

namespace App\Actions\Face;

use App\DTO\VerifyResult;
use App\Enums\FastApiStatus;
use App\Enums\VerificationStatus;
use App\Enums\VerificationType;
use App\Models\AttendanceSession;
use App\Models\AttendanceVerification;
use App\Models\Employee;
use App\Services\Integration\FastApiService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Verify an employee's face via the FastAPI AI service.
 *
 * Laravel is the final decision authority. FastAPI returns AI facts only
 * (verified, confidence, liveness). Laravel combines these with attendance
 * context, geofence, schedule, and policy to produce the final verification
 * status stored in AttendanceVerification.
 *
 * Key business rules enforced here:
 * - A service failure (unavailable/timeout) is NEVER treated as "verified".
 * - Liveness must pass when required by policy.
 * - The AI "verified" flag is necessary but not sufficient — Laravel decides.
 */
class VerifyFaceAction
{
    public function __construct(
        protected FastApiService $fastApi,
    ) {}

    /**
     * Execute face verification for the given employee.
     *
     * @param  string  $imagePath  Absolute path to the probe face image.
     * @param  string|null  $embeddingReference  Opaque reference from enrollment.
     * @param  AttendanceSession|null  $session  Optional session context.
     * @param  bool  $livenessRequired  Whether liveness check must pass.
     * @return array{status: FastApiStatus, passed: bool, result: VerifyResult|null, error: string|null}
     */
    public function execute(
        Employee $employee,
        string $imagePath,
        ?string $embeddingReference = null,
        ?AttendanceSession $session = null,
        bool $livenessRequired = true,
        bool $persistRecord = true,
    ): array {
        $response = $this->fastApi->verify(
            $employee->employee_code,
            $imagePath,
            $embeddingReference,
        );

        if ($response['status'] !== FastApiStatus::Available || $response['data'] === null) {
            return [
                'status' => $response['status'],
                'passed' => false,
                'result' => null,
                'error' => $response['error'],
            ];
        }

        $result = VerifyResult::fromArray($response['data']);

        $details = [
            'action' => 'verify',
            'model_version' => $result->modelVersion,
            'embedding_reference' => $embeddingReference,
            'confidence' => $result->confidence,
            'face_detected' => $result->faceDetected,
            'liveness' => $result->liveness,
            'liveness_reason' => $result->livenessReason,
            'processing_time_ms' => $result->processingTimeMs,
            'quality_score' => $result->qualityScore,
            'liveness_required' => $livenessRequired,
        ];

        $passed = $this->evaluateDecision($result, $livenessRequired);

        $verificationStatus = $passed ? VerificationStatus::Passed : VerificationStatus::Failed;

        if ($persistRecord) {
            DB::transaction(function () use ($employee, $session, $verificationStatus, $details) {
                AttendanceVerification::create([
                    'employee_id' => $employee->id,
                    'attendance_id' => $session ? $session->attendance_record_id : null,
                    'attendance_session_id' => $session?->id,
                    'verification_type' => VerificationType::Face->value,
                    'status' => $verificationStatus->value,
                    'details' => $details,
                    'verified_at' => $verificationStatus === VerificationStatus::Passed
                        ? now()
                        : null,
                ]);
            });
        }

        return [
            'status' => FastApiStatus::Available,
            'passed' => $passed,
            'result' => $result,
            'error' => null,
        ];
    }

    /**
     * Evaluate the final business decision from AI facts.
     *
     * Laravel decides: FastAPI's "verified" is a necessary but not sufficient
     * condition. Liveness must pass when required.
     */
    protected function evaluateDecision(VerifyResult $result, bool $livenessRequired): bool
    {
        if (! $result->faceDetected) {
            return false;
        }

        if (! $result->verified) {
            return false;
        }

        if ($livenessRequired && ! $result->liveness) {
            return false;
        }

        return true;
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
