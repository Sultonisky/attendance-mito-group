<?php

namespace App\Actions\Outsource;

use App\Actions\Audit\RecordAuditAction;
use App\Domain\Attendance\DTOs\AttendanceOperationData;
use App\Domain\Attendance\Engines\AttendanceEngine;
use App\Domain\Attendance\Exceptions\AttendanceAlreadyCheckedInException;
use App\Domain\Attendance\Exceptions\AttendanceBlockedByPolicyException;
use App\Domain\Attendance\Exceptions\InvalidLocationException;
use App\Domain\Attendance\Exceptions\OutsideGeofenceException;
use App\Enums\AttendanceEventType;
use App\Exceptions\Domain\InactiveSubjectException;
use App\Models\Outsource;
use App\Models\OutsourceAttendanceSession;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

class OutsourceCheckIn
{
    public function __construct(
        protected AttendanceEngine $engine,
        protected RecordAuditAction $audit,
    ) {}

    public function execute(Outsource $outsource, OutsourceAttendanceSession $session, CarbonImmutable $occurredAt, array $context): array
    {
        $operationData = new AttendanceOperationData(
            employeeId: 0,
            latitude: (float) $context['latitude'],
            longitude: (float) $context['longitude'],
            accuracy: isset($context['accuracy']) ? (float) $context['accuracy'] : null,
            deviceIdentifier: $context['device_identifier'] ?? null,
            source: $context['source'] ?? 'web',
            workLocationId: $session->work_location_id,
            occurredAt: $occurredAt,
            eventType: AttendanceEventType::CheckIn,
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

        $result = DB::transaction(function () use ($domainResult, $session, $context) {
            $record = $domainResult->attendanceRecord;
            $attendanceSession = $domainResult->session;

            $this->audit->execute(
                null,
                'outsource.attendance.check_in',
                $record,
                null,
                [
                    'status' => $record->status,
                    'session_status' => $attendanceSession->status,
                    'check_in_at' => $attendanceSession->check_in_at?->toIso8601String(),
                    'outsource_id' => $session->outsource_id,
                    'store_id' => $session->work_location_id,
                ],
                null,
                ['session_id' => $session->id, 'record_id' => $record->id]
            );

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
