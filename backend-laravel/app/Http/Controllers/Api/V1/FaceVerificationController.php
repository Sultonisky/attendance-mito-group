<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Face\EnrollFaceAction;
use App\Actions\Face\VerifyFaceAction;
use App\Enums\FastApiStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Face\EnrollFaceRequest;
use App\Http\Requests\Face\VerifyFaceRequest;
use App\Models\Employee;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Storage;

/**
 * Face verification API endpoints.
 *
 * Controllers are thin — they delegate to Actions which contain the business
 * logic. FastAPI returns AI facts; Laravel makes the final decision.
 */
class FaceVerificationController extends Controller
{
    /**
     * Enroll an employee's face via the FastAPI AI service.
     *
     * Requires the `employees.manage-faces` permission.
     */
    public function enroll(EnrollFaceRequest $request, EnrollFaceAction $action): JsonResponse
    {
        $employee = Employee::findOrFail($request->integer('employee_id'));

        $path = $request->file('image')->store('face-enrollment-temp');
        $tempPath = Storage::path($path);

        $result = $action->execute($employee, $tempPath);

        $action->cleanup($path);

        if (! $result['enrolled']) {
            return response()->json([
                'success' => false,
                'error' => $result['error'] ?? 'Enrollment failed.',
            ], 422);
        }

        return response()->json([
            'success' => true,
            'data' => [
                'employee_id' => $employee->id,
                'employee_code' => $employee->employee_code,
                'model_version' => $result['result']->modelVersion,
                'embedding_reference' => $result['result']->embeddingReference,
                'face_detected' => $result['result']->faceDetected,
                'quality_score' => $result['result']->qualityScore,
            ],
        ]);
    }

    /**
     * Verify an employee's face via the FastAPI AI service.
     *
     * Laravel evaluates: FastAPI's "verified" flag, liveness (when required),
     * face detection, and confidence — combined with attendance context —
     * to produce the final verification decision.
     */
    public function verify(VerifyFaceRequest $request, VerifyFaceAction $action): JsonResponse
    {
        $employee = Employee::findOrFail($request->integer('employee_id'));

        $path = $request->file('image')->store('face-verify-temp');
        $tempPath = Storage::path($path);

        $result = $action->execute(
            $employee,
            $tempPath,
            $request->string('embedding_reference')->toString(),
            livenessRequired: $request->boolean('liveness_required', true),
        );

        $action->cleanup($path);

        if ($result['status'] !== FastApiStatus::Available) {
            return response()->json([
                'success' => false,
                'error' => $result['error'] ?? 'AI service unavailable.',
            ], 503);
        }

        return response()->json([
            'success' => true,
            'data' => [
                'employee_id' => $employee->id,
                'employee_code' => $employee->employee_code,
                'passed' => $result['passed'],
                'ai_facts' => [
                    'verified' => $result['result']->verified,
                    'confidence' => $result['result']->confidence,
                    'liveness' => $result['result']->liveness,
                    'liveness_reason' => $result['result']->livenessReason,
                    'face_detected' => $result['result']->faceDetected,
                    'model_version' => $result['result']->modelVersion,
                    'processing_time_ms' => $result['result']->processingTimeMs,
                    'quality_score' => $result['result']->qualityScore,
                ],
            ],
        ]);
    }
}
