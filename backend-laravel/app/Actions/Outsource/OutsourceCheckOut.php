<?php

namespace App\Actions\Outsource;

use App\Actions\Audit\RecordAuditAction;
use App\Domain\Attendance\DTOs\AttendanceOperationData;
use App\Domain\Attendance\Engines\AttendanceEngine;
use App\Domain\Attendance\Exceptions\AttendanceBlockedByPolicyException;
use App\Domain\Attendance\Exceptions\InvalidLocationException;
use App\Domain\Attendance\Exceptions\NoOpenAttendanceSessionException;
use App\Domain\Attendance\Exceptions\OutsideGeofenceException;
use App\Enums\AttendanceEventType;
use App\Exceptions\Domain\InactiveSubjectException;
use App\Models\Outsource;
use App\Services\Outsource\Session\OutsourceSessionData;
use App\Services\Outsource\Session\OutsourceSessionStoreInterface;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

class OutsourceCheckOut
{
    public function __construct(
        protected AttendanceEngine $engine,
        protected RecordAuditAction $audit,
        protected OutsourceSessionStoreInterface $sessions,
    ) {}

    public function execute(Outsource $outsource, OutsourceSessionData $session, CarbonImmutable $occurredAt, array $context): array
    {
        $fingerprint = trim((string) ($context['device_fingerprint'] ?? ''));
        if ($fingerprint === '' || strlen($fingerprint) < 16) {
            return [
                'success' => false,
                'error' => 'DEVICE_REQUIRED',
                'message' => 'Device fingerprint is required.',
                'geofence' => ['passed' => false, 'distance_meters' => null, 'method' => 'skipped'],
            ];
        }

        if (
            $session->deviceFingerprint !== ''
            && ! hash_equals($session->deviceFingerprint, $fingerprint)
        ) {
            return [
                'success' => false,
                'error' => 'DEVICE_MISMATCH',
                'message' => 'Sesi ini terikat ke perangkat lain. Mulai ulang sesi dari perangkat yang sama.',
                'geofence' => ['passed' => false, 'distance_meters' => null, 'method' => 'skipped'],
            ];
        }

        $accuracy = $context['accuracy_meters'] ?? $context['accuracy'] ?? null;

        $operationData = new AttendanceOperationData(
            employeeId: 0,
            latitude: (float) $context['latitude'],
            longitude: (float) $context['longitude'],
            accuracy: $accuracy !== null ? (float) $accuracy : null,
            deviceIdentifier: $fingerprint,
            source: $context['source'] ?? 'web',
            workLocationId: $session->storeId,
            occurredAt: $occurredAt,
            eventType: AttendanceEventType::CheckOut,
        );

        try {
            $domainResult = $this->engine->checkOut($outsource, $operationData);
        } catch (NoOpenAttendanceSessionException $e) {
            return [
                'success' => false,
                'error' => 'NO_OPEN_ATTENDANCE',
                'message' => 'No open attendance session found.',
                'geofence' => ['passed' => false, 'distance_meters' => null, 'method' => 'skipped'],
            ];
        } catch (OutsideGeofenceException $e) {
            return [
                'success' => false,
                'error' => 'OUTSIDE_GEOFENCE',
                'message' => 'You are outside the attendance location.',
                'geofence' => ['passed' => false, 'distance_meters' => null, 'method' => 'skipped'],
            ];
        } catch (InactiveSubjectException $e) {
            return [
                'success' => false,
                'error' => 'INACTIVE_OUTSOURCE',
                'message' => 'Outsource is inactive.',
                'geofence' => ['passed' => false, 'distance_meters' => null, 'method' => 'skipped'],
            ];
        } catch (AttendanceBlockedByPolicyException $e) {
            return [
                'success' => false,
                'error' => 'ATTENDANCE_BLOCKED',
                'message' => $e->getMessage(),
                'geofence' => ['passed' => false, 'distance_meters' => null, 'method' => 'skipped'],
            ];
        } catch (InvalidLocationException $e) {
            return [
                'success' => false,
                'error' => 'INVALID_LOCATION',
                'message' => $e->getMessage(),
                'geofence' => ['passed' => false, 'distance_meters' => null, 'method' => 'skipped'],
            ];
        } catch (\Throwable $e) {
            return [
                'success' => false,
                'error' => 'CHECK_OUT_FAILED',
                'message' => 'Unable to record check-out.',
                'geofence' => ['passed' => false, 'distance_meters' => null, 'method' => 'skipped'],
            ];
        }

        $result = DB::transaction(function () use ($domainResult, $session) {
            $record = $domainResult->attendanceRecord;
            $attendanceSession = $domainResult->session;

            $this->audit->execute(
                null,
                'outsource.attendance.check_out',
                $attendanceSession,
                [
                    'check_in_at' => $attendanceSession->check_in_at?->toIso8601String(),
                    'check_out_at' => $attendanceSession->check_out_at?->toIso8601String(),
                    'duration_minutes' => $attendanceSession->duration_minutes,
                    'status' => $attendanceSession->status,
                ],
                [
                    'check_in_at' => $attendanceSession->check_in_at?->toIso8601String(),
                    'check_out_at' => $attendanceSession->check_out_at?->toIso8601String(),
                    'duration_minutes' => $attendanceSession->duration_minutes,
                    'status' => $attendanceSession->status,
                    'attendance_record_status' => $record->status,
                ],
                null,
                ['session_id' => $session->id, 'record_id' => $record->id]
            );

            return ['record' => $record, 'session' => $attendanceSession];
        });

        // Ephemeral session is invalidated only after durable attendance succeeds.
        $this->sessions->delete($session->id);

        return [
            'success' => true,
            'record' => $result['record'],
            'session' => $result['session'],
            'geofence' => $domainResult->geofence,
            'message' => 'Check-out recorded successfully.',
            'invalidate_cookie' => true,
        ];
    }
}
