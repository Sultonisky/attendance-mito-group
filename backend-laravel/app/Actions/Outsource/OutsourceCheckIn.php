<?php

namespace App\Actions\Outsource;

use App\Actions\Audit\RecordAuditAction;
use App\Domain\Attendance\DTOs\AttendanceOperationData;
use App\Domain\Attendance\Engines\AttendanceEngine;
use App\Domain\Attendance\Exceptions\AttendanceAlreadyCheckedInException;
use App\Domain\Attendance\Exceptions\AttendanceBlockedByPolicyException;
use App\Domain\Attendance\Exceptions\AttendanceDayAlreadyCompletedException;
use App\Domain\Attendance\Exceptions\InvalidLocationException;
use App\Domain\Attendance\Exceptions\OutsideGeofenceException;
use App\Enums\AttendanceEventType;
use App\Exceptions\Domain\InactiveSubjectException;
use App\Models\Outsource;
use App\Services\Outsource\OutsourceDeviceLockService;
use App\Services\Outsource\Session\OutsourceSessionData;
use App\Services\Outsource\Session\OutsourceSessionStoreInterface;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class OutsourceCheckIn
{
    public function __construct(
        protected AttendanceEngine $engine,
        protected RecordAuditAction $audit,
        protected OutsourceDeviceLockService $deviceLock,
        protected OutsourceSessionStoreInterface $sessions,
    ) {}

    public function execute(
        Outsource $outsource,
        OutsourceSessionData $session,
        CarbonImmutable $occurredAt,
        array $context,
        ?Request $request = null,
    ): array {
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

        $busyOutsourceId = $this->deviceLock->findOpenOutsourceIdForDevice($fingerprint, $outsource->id);
        if ($busyOutsourceId !== null) {
            return [
                'success' => false,
                'error' => 'DEVICE_BUSY',
                'message' => 'Perangkat ini masih digunakan untuk absensi personel lain yang belum clock-out.',
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
            eventType: AttendanceEventType::CheckIn,
            pinId: isset($context['pin_id']) ? (int) $context['pin_id'] : null,
        );

        try {
            $domainResult = $this->engine->checkIn($outsource, $operationData);
        } catch (AttendanceAlreadyCheckedInException $e) {
            return [
                'success' => false,
                'error' => 'ATTENDANCE_ALREADY_OPEN',
                'message' => 'Attendance session already open for this period.',
                'geofence' => ['passed' => false, 'distance_meters' => null, 'method' => 'skipped'],
            ];
        } catch (AttendanceDayAlreadyCompletedException $e) {
            return [
                'success' => false,
                'error' => 'ATTENDANCE_DAY_COMPLETED',
                'message' => $e->getMessage(),
                'geofence' => ['passed' => false, 'distance_meters' => null, 'method' => 'skipped'],
            ];
        } catch (OutsideGeofenceException $e) {
            return [
                'success' => false,
                'error' => 'OUTSIDE_GEOFENCE',
                'message' => 'You are outside the attendance location.',
                'geofence' => ['passed' => false, 'distance_meters' => null, 'method' => 'skipped'],
            ];
        } catch (InvalidLocationException $e) {
            return [
                'success' => false,
                'error' => 'INVALID_LOCATION',
                'message' => $e->getMessage(),
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
        } catch (\Throwable $e) {
            return [
                'success' => false,
                'error' => 'CHECK_IN_FAILED',
                'message' => 'Unable to record check-in.',
                'geofence' => ['passed' => false, 'distance_meters' => null, 'method' => 'skipped'],
            ];
        }

        $result = DB::transaction(function () use ($domainResult, $session, $fingerprint, $context, $outsource, $request) {
            $nextSession = $session;
            if ($session->deviceFingerprint === '') {
                $nextSession = $nextSession->withDeviceFingerprint($fingerprint);
            }
            if (isset($context['pin_id'])) {
                $nextSession = $nextSession->withCheckInPinId((int) $context['pin_id']);
            }
            if ($nextSession !== $session) {
                $this->sessions->put($nextSession);
            }

            $record = $domainResult->attendanceRecord;
            $attendanceSession = $domainResult->session;

            $this->audit->execute(RecordAuditAction::forOutsource(
                outsource: $outsource,
                action: 'outsource.attendance.check_in',
                subject: $record,
                newValues: [
                    'status' => $record->status,
                    'session_status' => $attendanceSession->status,
                    'check_in_at' => $attendanceSession->check_in_at?->toIso8601String(),
                    'outsource_id' => $session->outsourceId,
                    'store_id' => $session->storeId,
                    'pin_id' => $context['pin_id'] ?? null,
                    'device_fingerprint' => $fingerprint,
                ],
                ipAddress: $request?->ip() ?? $session->ipAddress,
                userAgent: $request?->userAgent() ?? $session->userAgent,
                metadata: ['session_id' => $session->id, 'record_id' => $record->id],
            ));

            return ['record' => $record, 'session' => $attendanceSession];
        });

        return [
            'success' => true,
            'record' => $result['record'],
            'session' => $result['session'],
            'geofence' => $domainResult->geofence,
            'message' => 'Check-in recorded successfully.',
        ];
    }
}
