<?php

namespace App\Actions\Attendance;

use App\Actions\Audit\RecordAuditAction;
use App\Actions\Face\VerifyFaceAction;
use App\Domain\Attendance\DTOs\AttendanceOperationData;
use App\Domain\Attendance\Engines\AttendanceEngine;
use App\Domain\Attendance\Exceptions\AttendanceAlreadyCheckedInException;
use App\Domain\Attendance\Exceptions\AttendanceBlockedByPolicyException;
use App\Domain\Attendance\Exceptions\OutsideGeofenceException;
use App\DTO\VerifyResult;
use App\Enums\AttendanceEventType;
use App\Enums\AttendanceStatus;
use App\Enums\FastApiStatus;
use App\Enums\VerificationStatus;
use App\Enums\VerificationType;
use App\Exceptions\Domain\InactiveEmployeeException;
use App\Models\AttendanceRecord;
use App\Models\AttendanceSession;
use App\Models\AttendanceVerification;
use App\Models\Employee;
use App\Models\EmployeeFaceEmbedding;
use App\Models\EmployeeFaceProfile;
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
     * @return array{status: AttendanceStatus, record: AttendanceRecord|null, session: AttendanceSession|null, geofence: array, policy: array, error: string|null, conflict: bool}
     */
    public function execute(
        Employee $employee,
        CarbonImmutable $occurredAt,
        ?array $context = [],
        ?int $actorId = null,
        ?Request $request = null,
        ?string $faceImagePath = null
    ): array {
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
                'geofence' => [
                    'passed' => true,
                    'distance_meters' => null,
                    'method' => 'skipped',
                ],
                'policy' => [
                    'allowed' => true,
                    'reason' => null,
                    'policies' => [],
                ],
                'error' => 'Face verification failed.',
                'conflict' => false,
            ];
        }

        $operationData = new AttendanceOperationData(
            employeeId: $employee->id,
            latitude: (float) ($context['latitude'] ?? 0),
            longitude: (float) ($context['longitude'] ?? 0),
            accuracy: isset($context['accuracy']) ? (float) $context['accuracy'] : null,
            deviceIdentifier: $context['device_identifier'] ?? null,
            source: $context['source'] ?? 'app',
            workLocationId: isset($context['work_location_id']) ? (int) $context['work_location_id'] : null,
            occurredAt: $occurredAt,
            eventType: AttendanceEventType::CheckIn,
        );

        try {
            $domainResult = $this->engine->checkIn($employee, $operationData);
        } catch (InactiveEmployeeException $e) {
            return $this->mapError(AttendanceStatus::Absent, 'Employee employment status is inactive.', null, null, false);
        } catch (AttendanceAlreadyCheckedInException $e) {
            return [
                'status' => AttendanceStatus::Incomplete,
                'record' => null,
                'session' => null,
                'geofence' => [
                    'passed' => true,
                    'distance_meters' => null,
                    'method' => 'skipped',
                ],
                'policy' => [
                    'allowed' => false,
                    'reason' => 'Employee already has an open attendance session.',
                    'policies' => [],
                ],
                'error' => 'Employee already has an open attendance session.',
                'conflict' => true,
            ];
        } catch (OutsideGeofenceException $e) {
            return $this->mapError(AttendanceStatus::Incomplete, 'Employee is outside the approved work location geofence.', null, null, false);
        } catch (AttendanceBlockedByPolicyException $e) {
            return $this->mapError(AttendanceStatus::Absent, $e->getMessage(), null, null, false);
        } catch (\Throwable $e) {
            return $this->mapError(AttendanceStatus::Incomplete, $e->getMessage(), null, null, false);
        }

        $result = DB::transaction(function () use (
            $domainResult,
            $employee,
            $actorId,
            $request,
            $verificationResult,
            $context
        ) {
            $record = $domainResult->attendanceRecord;
            $session = $domainResult->session;

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
            'status' => AttendanceStatus::from($domainResult->attendanceRecord->status),
            'record' => $result['record'],
            'session' => $result['session'],
            'geofence' => $domainResult->geofence,
            'policy' => $domainResult->policy,
            'error' => null,
            'conflict' => false,
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
     * @return array{status: AttendanceStatus, record: AttendanceRecord|null, session: AttendanceSession|null, geofence: array, policy: array, error: string|null, conflict: bool}
     */
    private function mapError(
        AttendanceStatus $status,
        string $error,
        ?AttendanceRecord $record,
        ?AttendanceSession $session,
        bool $conflict
    ): array {
        return [
            'status' => $status,
            'record' => $record,
            'session' => $session,
            'geofence' => [
                'passed' => false,
                'distance_meters' => null,
                'method' => 'skipped',
            ],
            'policy' => [
                'allowed' => false,
                'reason' => $error,
                'policies' => [],
            ],
            'error' => $error,
            'conflict' => $conflict,
        ];
    }
}
