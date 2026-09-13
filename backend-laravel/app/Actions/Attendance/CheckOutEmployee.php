<?php

namespace App\Actions\Attendance;

use App\Actions\Audit\RecordAuditAction;
use App\Actions\Face\VerifyFaceAction;
use App\DTO\VerifyResult;
use App\Enums\AttendanceSessionStatus;
use App\Enums\AttendanceStatus;
use App\Enums\FastApiStatus;
use App\Enums\VerificationStatus;
use App\Enums\VerificationType;
use App\Models\AttendanceEvent;
use App\Models\AttendanceRecord;
use App\Models\AttendanceSession;
use App\Models\AttendanceVerification;
use App\Models\Employee;
use App\Models\EmployeeFaceEmbedding;
use App\Models\EmployeeFaceProfile;
use App\Services\Attendance\AttendanceEngine;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class CheckOutEmployee
{
    public function __construct(
        protected AttendanceEngine $engine,
        protected RecordAuditAction $audit,
        protected VerifyFaceAction $verifyFace,
    ) {}

    /**
     * @param  array<string, mixed>|null  $context
     * @return array{status: AttendanceStatus, record: AttendanceRecord, session: AttendanceSession, geofence: array, policy: array, error: string|null}
     */
    public function execute(
        Employee $employee,
        CarbonImmutable $occurredAt,
        ?array $context = [],
        ?int $actorId = null,
        ?Request $request = null,
        ?string $faceImagePath = null
    ): array {
        $evaluation = $this->engine->evaluateCheckOut($employee, $occurredAt, $context);

        if ($evaluation['error'] !== null || $evaluation['session'] === null) {
            return $this->mapResult($evaluation);
        }

        $verificationResult = $this->verifyFaceForAttendance(
            $employee,
            $faceImagePath,
            $context['embedding_reference'] ?? null,
        );

        if ($verificationResult['status'] !== FastApiStatus::Available || ! $verificationResult['passed']) {
            return [
                'status' => AttendanceStatus::Incomplete,
                'record' => $evaluation['record'],
                'session' => $evaluation['session'],
                'geofence' => $evaluation['geofence'],
                'policy' => $evaluation['policy'],
                'error' => 'Face verification failed.',
            ];
        }

        $checkInAt = CarbonImmutable::parse($evaluation['session']->check_in_at);
        $durationMinutes = (int) $occurredAt->diffInMinutes($checkInAt);

        DB::transaction(function () use (
            $evaluation, $employee, $occurredAt, $durationMinutes, $actorId, $request, $verificationResult
        ) {
            $session = $evaluation['session'];
            $oldSessionValues = [
                'check_in_at' => $session->check_in_at?->toIso8601String(),
                'check_out_at' => $session->check_out_at?->toIso8601String(),
                'duration_minutes' => $session->duration_minutes,
                'status' => $session->status,
            ];

            $session->update([
                'check_out_at' => $occurredAt,
                'duration_minutes' => $durationMinutes,
                'status' => AttendanceSessionStatus::Closed->value,
            ]);

            $record = $evaluation['record'];
            $oldRecordStatus = $record->status;
            $record->update([
                'status' => $this->resolveRecordStatus($record, $session),
            ]);

            foreach ($evaluation['events'] as $eventData) {
                AttendanceEvent::create([
                    'employee_id' => $employee->id,
                    'attendance_id' => $record->id,
                    'attendance_session_id' => $session->id,
                    'event_type' => $eventData['event_type'],
                    'occurred_at' => $eventData['occurred_at'],
                    'latitude' => $eventData['latitude'] ?? null,
                    'longitude' => $eventData['longitude'] ?? null,
                    'accuracy_meters' => $eventData['accuracy_meters'] ?? null,
                    'source' => $eventData['source'] ?? 'app',
                    'device_metadata' => $eventData['device_metadata'] ?? null,
                ]);
            }

            if ($verificationResult['result'] !== null) {
                $aiResult = $verificationResult['result'];

                AttendanceVerification::create([
                    'employee_id' => $employee->id,
                    'attendance_id' => $record->id,
                    'attendance_session_id' => $session->id,
                    'verification_type' => VerificationType::Face->value,
                    'status' => VerificationStatus::Passed->value,
                    'details' => [
                        'action' => 'attendance_check_out',
                        'model_version' => $aiResult->modelVersion,
                        'embedding_reference' => $context['embedding_reference'] ?? null,
                        'confidence' => $aiResult->confidence,
                        'face_detected' => $aiResult->faceDetected,
                        'liveness' => $aiResult->liveness,
                        'liveness_reason' => $aiResult->livenessReason,
                        'processing_time_ms' => $aiResult->processingTimeMs,
                        'quality_score' => $aiResult->qualityScore,
                    ],
                    'verified_at' => now(),
                ]);
            }

            $this->audit->execute(
                $actorId,
                'attendance.check_out',
                $session,
                $oldSessionValues,
                [
                    'check_in_at' => $session->check_in_at?->toIso8601String(),
                    'check_out_at' => $session->check_out_at?->toIso8601String(),
                    'duration_minutes' => $session->duration_minutes,
                    'status' => $session->status,
                    'attendance_record_status' => $record->status,
                    'old_attendance_record_status' => $oldRecordStatus,
                ],
                $request,
                ['record_id' => $record->id]
            );
        });

        return $this->mapResult($evaluation);
    }

    /**
     * Verify face for attendance when the employee has an active face profile.
     *
     * @return array{status: FastApiStatus, passed: bool, result: VerifyResult|null, error: string|null}
     */
    private function verifyFaceForAttendance(
        Employee $employee,
        ?string $imagePath,
        ?string $embeddingReference
    ): array {
        $activeProfile = EmployeeFaceProfile::where('employee_id', $employee->id)
            ->where('status', 'active')
            ->first();

        if ($activeProfile === null) {
            return [
                'status' => FastApiStatus::Available,
                'passed' => true,
                'result' => null,
                'error' => null,
            ];
        }

        if ($imagePath === null) {
            return [
                'status' => FastApiStatus::Available,
                'passed' => false,
                'result' => null,
                'error' => 'Face image is required for attendance verification.',
            ];
        }

        $activeEmbeddingReference = $embeddingReference
            ?? EmployeeFaceEmbedding::where('employee_face_profile_id', $activeProfile->id)
                ->where('status', 'active')
                ->value('embedding_reference');

        return $this->verifyFace->execute(
            $employee,
            $imagePath,
            $activeEmbeddingReference,
            null,
            true,
            false,
        );
    }

    private function resolveRecordStatus(AttendanceRecord $record, AttendanceSession $session): string
    {
        if ($record->status !== AttendanceStatus::Incomplete->value) {
            return $record->status;
        }

        if ($session->duration_minutes !== null && $session->duration_minutes >= 0) {
            return AttendanceStatus::Present->value;
        }

        return AttendanceStatus::Incomplete->value;
    }

    /**
     * @return array{status: AttendanceStatus, record: AttendanceRecord|null, session: AttendanceSession|null, geofence: array, policy: array, error: string|null}
     */
    private function mapResult(array $evaluation): array
    {
        return [
            'status' => $evaluation['status'],
            'record' => $evaluation['record'],
            'session' => $evaluation['session'],
            'geofence' => $evaluation['geofence'],
            'policy' => $evaluation['policy'],
            'error' => $evaluation['error'] ?? null,
        ];
    }
}
