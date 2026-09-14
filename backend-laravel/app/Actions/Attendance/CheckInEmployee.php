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

class CheckInEmployee
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
        $evaluation = $this->engine->evaluateCheckIn($employee, $occurredAt, $context);

        if ($evaluation['error'] !== null) {
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
                'record' => null,
                'session' => null,
                'geofence' => $evaluation['geofence'],
                'policy' => $evaluation['policy'],
                'error' => 'Face verification failed.',
            ];
        }

        $result = DB::transaction(function () use (
            $evaluation,
            $employee,
            $occurredAt,
            $actorId,
            $request,
            $verificationResult
        ) {
            $record = AttendanceRecord::query()
                ->where('employee_id', $employee->id)
                ->whereDate('attendance_date', $occurredAt->toDateString())
                ->first();

            if ($record === null) {
                $record = AttendanceRecord::create([
                    'employee_id' => $employee->id,
                    'attendance_date' => $occurredAt->toDateString(),
                    'status' => $evaluation['status']->value,
                ]);
            } elseif ($evaluation['status']->value !== $record->status) {
                $record->update(['status' => $evaluation['status']->value]);
            }

            $session = AttendanceSession::create([
                'attendance_record_id' => $record->id,
                'check_in_at' => $occurredAt,
                'status' => AttendanceSessionStatus::Open->value,
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
                        'action' => 'attendance_check_in',
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
                'attendance.check_in',
                $record,
                null,
                [
                    'status' => $record->status,
                    'session_status' => $session->status,
                    'check_in_at' => $session->check_in_at?->toIso8601String(),
                ],
                $request,
                ['session_id' => $session->id]
            );

            return ['record' => $record, 'session' => $session];
        });

        return [
            'status' => $evaluation['status'],
            'record' => $result['record'],
            'session' => $result['session'],
            'geofence' => $evaluation['geofence'],
            'policy' => $evaluation['policy'],
            'error' => null,
        ];
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
