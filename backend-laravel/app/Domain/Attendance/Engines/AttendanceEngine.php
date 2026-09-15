<?php

namespace App\Domain\Attendance\Engines;

use App\Domain\Attendance\DTOs\AttendanceOperationData;
use App\Domain\Attendance\DTOs\AttendanceResultData;
use App\Domain\Attendance\Exceptions\AttendanceAlreadyCheckedInException;
use App\Domain\Attendance\Exceptions\AttendanceBlockedByPolicyException;
use App\Domain\Attendance\Exceptions\AttendanceOutsideGeofenceException;
use App\Domain\Attendance\Exceptions\InvalidLocationException;
use App\Domain\Attendance\Exceptions\NoOpenAttendanceSessionException;
use App\Domain\Attendance\Exceptions\OutsideGeofenceException;
use App\Domain\Attendance\Rules\AttendanceStateRule;
use App\Domain\Attendance\Rules\EarlyCheckoutRule;
use App\Domain\Attendance\Rules\GeofenceRule;
use App\Domain\Attendance\Rules\GpsValidationRule;
use App\Domain\Attendance\Rules\LateDetectionRule;
use App\Domain\Policy\Engines\PolicyEngine;
use App\Domain\Schedule\Engines\ScheduleEngine;
use App\Enums\AttendanceSessionStatus;
use App\Exceptions\Domain\InactiveEmployeeException;
use App\Models\AttendanceEvent;
use App\Models\AttendanceRecord;
use App\Models\AttendanceSession;
use App\Models\Employee;
use App\Models\Policy;
use App\Models\PolicyAssignment;
use App\Models\ScheduleAssignment;
use App\Models\Shift;
use App\Models\WorkLocation;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;

/**
 * Coordinates attendance operations.
 *
 * The engine is a thin orchestrator. It delegates to:
 * - PolicyEngine for policy resolution
 * - ScheduleEngine for schedule resolution
 * - Domain rules for GPS, geofence, late, and state determination
 *
 * It does not contain business logic itself.
 */
class AttendanceEngine
{
    public function __construct(
        private PolicyEngine $policyEngine,
        private ScheduleEngine $scheduleEngine,
        private GpsValidationRule $gpsValidationRule,
        private GeofenceRule $geofenceRule,
        private LateDetectionRule $lateDetectionRule,
        private EarlyCheckoutRule $earlyCheckoutRule,
        private AttendanceStateRule $attendanceStateRule,
    ) {}

    /**
     * Perform check-in for an employee.
     */
    public function checkIn(Employee $employee, AttendanceOperationData $data): AttendanceResultData
    {
        if ($employee->end_date && $employee->end_date->isPast()) {
            throw new InactiveEmployeeException('Employee has ended employment.');
        }

        $this->gpsValidationRule->validate($data->latitude, $data->longitude, $data->accuracy);

        $date = $this->resolveWorkDate($data->occurredAt, $employee);

        $policyResult = $this->resolveAttendancePolicy($employee, $date, 'check_in_blocked');
        if (! $policyResult['allowed']) {
            throw new AttendanceBlockedByPolicyException($policyResult['reason']);
        }

        $scheduleResult = $this->resolveAttendanceSchedule($employee, $date);
        if (! $scheduleResult['hasSchedule']) {
            throw new AttendanceBlockedByPolicyException('No active schedule found for this date.');
        }

        $workLocation = $this->resolveWorkLocation($data->workLocationId);
        $geofenceResult = $this->evaluateGeofence($workLocation, $data->latitude, $data->longitude);
        if (! $geofenceResult['passed']) {
            throw new OutsideGeofenceException('Employee is outside the approved work location geofence.');
        }

        $existingRecord = AttendanceRecord::where('employee_id', $employee->id)
            ->whereDate('attendance_date', $date->toDateString())
            ->first();

        if ($existingRecord && $existingRecord->sessions()->where('status', AttendanceSessionStatus::Open->value)->exists()) {
            throw new AttendanceAlreadyCheckedInException('Employee already has an open attendance session.');
        }

        try {
            $record = $existingRecord ?? $this->createAttendanceRecord($employee, $date);
        } catch (UniqueConstraintViolationException $e) {
            throw new AttendanceAlreadyCheckedInException('Employee already has an attendance record for this date.', previous: $e);
        }

        $session = $this->createSession($record, $data->occurredAt);
        $event = $this->createEvent($employee, $record, $session, $data);

        $scheduledStart = $this->resolveScheduledStart($scheduleResult['shift'], $data->occurredAt);
        $isLate = $this->lateDetectionRule->isLate($data->occurredAt, $scheduledStart);

        $status = $this->attendanceStateRule->determine(
            hasPolicy: true,
            hasSchedule: $scheduleResult['hasSchedule'],
            hasOpenSession: false,
            isLate: $isLate,
            isEarlyCheckout: false,
        );

        $record->update(['status' => $status->value]);

        return new AttendanceResultData(
            attendanceRecord: $record->fresh(),
            session: $session,
            verification: null,
            geofence: $geofenceResult,
            policy: $policyResult,
            message: 'Check-in recorded successfully.',
        );
    }

    /**
     * Perform check-out for an employee.
     */
    public function checkOut(Employee $employee, AttendanceOperationData $data): AttendanceResultData
    {
        if ($employee->end_date && $employee->end_date->isPast()) {
            throw new InactiveEmployeeException('Employee has ended employment.');
        }

        $this->gpsValidationRule->validate($data->latitude, $data->longitude, $data->accuracy);

        $openSession = AttendanceSession::whereHas('attendanceRecord', function ($query) use ($employee) {
            $query->where('employee_id', $employee->id);
        })
            ->where('status', AttendanceSessionStatus::Open->value)
            ->orderByDesc('check_in_at')
            ->first();

        if (! $openSession) {
            throw new NoOpenAttendanceSessionException('No open attendance session found.');
        }

        $record = $openSession->attendanceRecord;

        $workLocation = $this->resolveWorkLocation($data->workLocationId);
        $geofenceResult = $this->evaluateGeofence($workLocation, $data->latitude, $data->longitude);
        if (! $geofenceResult['passed']) {
            throw new OutsideGeofenceException('Employee is outside the approved work location geofence.');
        }

        $checkInAt = CarbonImmutable::parse($openSession->check_in_at);
        $checkOutAt = $data->occurredAt;

        if ($checkOutAt->lessThanOrEqualTo($checkInAt)) {
            throw new InvalidLocationException('Check-out time must be after check-in time.');
        }

        $durationMinutes = (int) $checkInAt->diffInMinutes($checkOutAt);

        $scheduleResult = $this->resolveAttendanceSchedule($employee, CarbonImmutable::parse($record->attendance_date));
        $scheduledEnd = $this->resolveScheduledEnd($scheduleResult['shift'], $checkOutAt);
        $isEarlyCheckout = $this->earlyCheckoutRule->isEarlyCheckout($checkOutAt, $scheduledEnd);

        $policyResult = $this->resolveAttendancePolicy($employee, CarbonImmutable::parse($record->attendance_date), 'check_out_blocked');
        if (! $policyResult['allowed']) {
            throw new AttendanceBlockedByPolicyException($policyResult['reason']);
        }

        $openSession->update([
            'check_out_at' => $checkOutAt,
            'duration_minutes' => $durationMinutes,
            'status' => AttendanceSessionStatus::Closed->value,
        ]);

        $event = $this->createEvent($employee, $record, $openSession, $data);

        $hasOpenSession = false;

        $status = $this->attendanceStateRule->determine(
            hasPolicy: true,
            hasSchedule: $scheduleResult['hasSchedule'],
            hasOpenSession: $hasOpenSession,
            isLate: false,
            isEarlyCheckout: $isEarlyCheckout,
        );

        $record->update(['status' => $status->value]);

        return new AttendanceResultData(
            attendanceRecord: $record->fresh(),
            session: $openSession->fresh(),
            verification: null,
            geofence: $geofenceResult,
            policy: $policyResult,
            message: 'Check-out recorded successfully.',
        );
    }

    private function evaluateGeofence(?WorkLocation $workLocation, ?float $latitude, ?float $longitude): array
    {
        if ($workLocation === null || $latitude === null || $longitude === null) {
            return ['passed' => true, 'distance_meters' => null, 'method' => 'skipped'];
        }

        try {
            $this->geofenceRule->validate($workLocation, $latitude, $longitude);
            $distanceMeters = $this->queryGeofenceDistance($workLocation, $latitude, $longitude);

            return ['passed' => true, 'distance_meters' => $distanceMeters, 'method' => 'postgis'];
        } catch (AttendanceOutsideGeofenceException $e) {
            $distanceMeters = $this->queryGeofenceDistance($workLocation, $latitude, $longitude);

            return ['passed' => false, 'distance_meters' => $distanceMeters, 'method' => 'postgis'];
        }
    }

    private function queryGeofenceDistance(WorkLocation $workLocation, float $latitude, float $longitude): ?float
    {
        try {
            return WorkLocation::where('id', $workLocation->id)
                ->selectRaw('ST_DDistance(location_point, ST_SetSRID(ST_MakePoint(?, ?), 4326)::geography) AS distance_meters', [$longitude, $latitude])
                ->value('distance_meters');
        } catch (\Throwable $e) {
            return null;
        }
    }

    private function resolveAttendancePolicy(Employee $employee, CarbonImmutable $date, string $blockFlag): array
    {
        $policies = PolicyAssignment::query()
            ->where('employee_id', $employee->id)
            ->where('effective_from', '<=', $date->toDateString())
            ->where(function ($query) use ($date) {
                $query->whereNull('effective_to')
                    ->orWhere('effective_to', '>=', $date->toDateString());
            })
            ->whereHas('policy', function ($query) {
                $query->where('status', 'active');
            })
            ->with('policy')
            ->orderByDesc('effective_from')
            ->get();

        foreach ($policies as $entry) {
            $configuration = $entry->policy->configuration ?? [];

            if (isset($configuration['attendance'][$blockFlag]) && $configuration['attendance'][$blockFlag] === true) {
                return [
                    'allowed' => false,
                    'reason' => $entry->policy->name ?? 'Attendance blocked by policy.',
                    'policies' => $policies->all(),
                ];
            }
        }

        return [
            'allowed' => true,
            'reason' => null,
            'policies' => $policies->all(),
        ];
    }

    private function resolveAttendanceSchedule(Employee $employee, CarbonImmutable $date): array
    {
        $assignment = ScheduleAssignment::query()
            ->where('employee_id', $employee->id)
            ->where('effective_from', '<=', $date->toDateString())
            ->where(function ($query) use ($date) {
                $query->whereNull('effective_to')
                    ->orWhere('effective_to', '>=', $date->toDateString());
            })
            ->with(['workSchedule.shifts' => function ($query) {
                $query->orderBy('start_time');
            }])
            ->orderByDesc('effective_from')
            ->first();

        if ($assignment === null) {
            return [
                'hasSchedule' => false,
                'schedule' => null,
                'shift' => null,
                'assignment' => null,
            ];
        }

        $schedule = $assignment->workSchedule;

        return [
            'hasSchedule' => true,
            'schedule' => $schedule,
            'shift' => $schedule->shifts->first() ?? null,
            'assignment' => $assignment,
        ];
    }

    private function resolveWorkDate(CarbonImmutable $occurredAt, Employee $employee): CarbonImmutable
    {
        $scheduleResult = $this->resolveAttendanceSchedule($employee, $occurredAt);

        if ($scheduleResult['hasSchedule'] && $scheduleResult['shift'] !== null) {
            $shift = $scheduleResult['shift'];

            if ($shift->cross_midnight) {
                $endTime = CarbonImmutable::createFromFormat('H:i:s', $shift->end_time);

                if ($occurredAt->format('H:i:s') < $endTime->format('H:i:s')) {
                    return $occurredAt->subDay();
                }
            }
        }

        return $occurredAt;
    }

    private function resolveWorkLocation(?int $workLocationId): ?WorkLocation
    {
        if ($workLocationId) {
            return WorkLocation::findOrFail($workLocationId);
        }

        return null;
    }

    private function resolveScheduledStart(?Shift $shift, CarbonImmutable $at): ?CarbonImmutable
    {
        if ($shift === null) {
            return null;
        }

        $date = $at->toDateString();

        if ($shift->cross_midnight && $at->format('H:i:s') < $shift->end_time) {
            $date = $at->subDay()->toDateString();
        }

        return CarbonImmutable::createFromFormat('Y-m-d H:i:s', $date.' '.$shift->start_time);
    }

    private function resolveScheduledEnd(?Shift $shift, CarbonImmutable $at): ?CarbonImmutable
    {
        if ($shift === null) {
            return null;
        }

        $date = $at->toDateString();

        if ($shift->cross_midnight && $at->format('H:i:s') < $shift->end_time) {
            $date = $at->addDay()->toDateString();
        }

        return CarbonImmutable::createFromFormat('Y-m-d H:i:s', $date.' '.$shift->end_time);
    }

    private function createAttendanceRecord(Employee $employee, CarbonImmutable $date): AttendanceRecord
    {
        return AttendanceRecord::create([
            'employee_id' => $employee->id,
            'attendance_date' => $date->toDateString(),
            'status' => 'incomplete',
        ]);
    }

    private function createSession(AttendanceRecord $record, CarbonImmutable $checkInAt): AttendanceSession
    {
        return AttendanceSession::create([
            'attendance_record_id' => $record->id,
            'check_in_at' => $checkInAt,
            'status' => AttendanceSessionStatus::Open->value,
        ]);
    }

    private function createEvent(
        Employee $employee,
        AttendanceRecord $record,
        AttendanceSession $session,
        AttendanceOperationData $data,
    ): AttendanceEvent {
        return AttendanceEvent::create([
            'employee_id' => $employee->id,
            'attendance_id' => $record->id,
            'attendance_session_id' => $session->id,
            'event_type' => $data->eventType->value,
            'occurred_at' => $data->occurredAt,
            'latitude' => $data->latitude,
            'longitude' => $data->longitude,
            'accuracy_meters' => $data->accuracy,
            'source' => $data->source,
            'device_metadata' => [],
        ]);
    }
}
