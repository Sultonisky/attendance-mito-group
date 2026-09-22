<?php

namespace App\Domain\Attendance\Engines;

use App\Contracts\AttendanceSubject;
use App\Domain\Attendance\DTOs\AttendanceOperationData;
use App\Domain\Attendance\DTOs\AttendanceResultData;
use App\Domain\Attendance\Exceptions\AttendanceAlreadyCheckedInException;
use App\Domain\Attendance\Exceptions\AttendanceBlockedByPolicyException;
use App\Domain\Attendance\Exceptions\AttendanceDayAlreadyCompletedException;
use App\Domain\Attendance\Exceptions\AttendanceOutsideGeofenceException;
use App\Domain\Attendance\Exceptions\AttendanceSessionExpiredException;
use App\Domain\Attendance\Exceptions\InvalidLocationException;
use App\Domain\Attendance\Exceptions\NoOpenAttendanceSessionException;
use App\Domain\Attendance\Exceptions\OutsideGeofenceException;
use App\Domain\Attendance\Rules\AttendanceStateRule;
use App\Domain\Attendance\Rules\EarlyCheckoutRule;
use App\Domain\Attendance\Rules\GeofenceRule;
use App\Domain\Attendance\Rules\GpsValidationRule;
use App\Domain\Attendance\Rules\LateDetectionRule;
use App\Domain\Attendance\Services\OutsourceSessionExpiry;
use App\Domain\Policy\Engines\PolicyEngine;
use App\Domain\Schedule\Engines\ScheduleEngine;
use App\Enums\AttendanceSessionStatus;
use App\Exceptions\Domain\InactiveEmployeeException;
use App\Exceptions\Domain\InactiveSubjectException;
use App\Models\AttendanceEvent;
use App\Models\AttendanceRecord;
use App\Models\AttendanceSession;
use App\Models\Employee;
use App\Models\Outsource;
use App\Models\OutsourceStoreAssignment;
use App\Models\Policy;
use App\Models\PolicyAssignment;
use App\Models\ScheduleAssignment;
use App\Models\Shift;
use App\Models\WorkLocation;
use App\Support\AttendanceDateTime;
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
        private OutsourceSessionExpiry $outsourceSessionExpiry,
    ) {}

    /**
     * Perform check-in for an attendance subject.
     */
    public function checkIn(AttendanceSubject $subject, AttendanceOperationData $data): AttendanceResultData
    {
        if ($subject->getEndDate() && $subject->getEndDate()->isPast()) {
            if ($subject instanceof Employee) {
                throw new InactiveEmployeeException('Employee employment status is inactive.');
            }

            throw new InactiveSubjectException('Subject has ended.');
        }

        $this->gpsValidationRule->validate(
            $data->latitude,
            $data->longitude,
            $data->accuracy,
            (float) config('attendance.gps_max_accuracy_meters', 100)
        );

        $date = $this->resolveWorkDate($data->occurredAt, $subject);

        $policyResult = $this->resolvePolicyResult($subject, $date, 'check_in_blocked');
        if (! $policyResult['allowed']) {
            throw new AttendanceBlockedByPolicyException($policyResult['reason']);
        }

        $scheduleResult = $this->resolveScheduleResult($subject, $date);
        if (! $scheduleResult['hasSchedule']) {
            throw new AttendanceBlockedByPolicyException('No active schedule found for this date.');
        }

        $workLocation = $this->resolveWorkLocation($data->workLocationId);

        if ($subject instanceof Outsource) {
            $this->validateOutsourceAssignment($subject, $workLocation);
        }

        $geofenceRadius = $subject instanceof Outsource
            ? (float) config('attendance.outsource_geofence_radius_meters', 150)
            : null;
        $geofenceResult = $this->evaluateGeofence($workLocation, $data->latitude, $data->longitude, $geofenceRadius);
        if (! $geofenceResult['passed']) {
            throw new OutsideGeofenceException('Subject is outside the approved work location geofence.');
        }

        $existingRecord = $this->findExistingRecord($subject, $date);

        if ($subject instanceof Outsource && $existingRecord) {
            $this->assertOutsourceMayStartSession($existingRecord, $data->occurredAt);
        } elseif ($existingRecord && $existingRecord->sessions()->where('status', AttendanceSessionStatus::Open->value)->exists()) {
            throw new AttendanceAlreadyCheckedInException('Subject already has an open attendance session.');
        }

        try {
            $record = $existingRecord ?? $this->createAttendanceRecord($subject, $date);
        } catch (UniqueConstraintViolationException $e) {
            throw new AttendanceAlreadyCheckedInException('Subject already has an attendance record for this date.', previous: $e);
        }

        $session = $this->createSession($record, $data->occurredAt);
        $event = $this->createEvent($subject, $record, $session, $data);

        // Open IN without OUT is always incomplete — Present/Late only after a closed session.
        $status = $this->attendanceStateRule->determine(
            hasPolicy: $policyResult['allowed'],
            hasSchedule: $scheduleResult['hasSchedule'],
            hasOpenSession: true,
            isLate: false,
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
     * Perform check-out for an attendance subject.
     */
    public function checkOut(AttendanceSubject $subject, AttendanceOperationData $data): AttendanceResultData
    {
        if ($subject->getEndDate() && $subject->getEndDate()->isPast()) {
            if ($subject instanceof Employee) {
                throw new InactiveEmployeeException('Employee employment status is inactive.');
            }

            throw new InactiveSubjectException('Subject has ended.');
        }

        $this->gpsValidationRule->validate(
            $data->latitude,
            $data->longitude,
            $data->accuracy,
            (float) config('attendance.gps_max_accuracy_meters', 100)
        );

        $openSession = AttendanceSession::whereHas('attendanceRecord', function ($query) use ($subject) {
            if ($subject instanceof Employee) {
                $query->where('employee_id', $subject->getId());
            } else {
                $query->where('outsource_id', $subject->getId());
            }
        })
            ->where('status', AttendanceSessionStatus::Open->value)
            ->orderByDesc('check_in_at')
            ->first();

        if (! $openSession) {
            throw new NoOpenAttendanceSessionException('No open attendance session found.');
        }

        $record = $openSession->attendanceRecord;

        if ($subject instanceof Outsource) {
            $this->assertOutsourceSessionStillCloseable($openSession, $data->occurredAt);
        }

        $workLocation = $this->resolveWorkLocation($data->workLocationId);

        if ($subject instanceof Outsource) {
            $this->validateOutsourceAssignment($subject, $workLocation);
        }

        $geofenceRadius = $subject instanceof Outsource
            ? (float) config('attendance.outsource_geofence_radius_meters', 150)
            : null;
        $geofenceResult = $this->evaluateGeofence($workLocation, $data->latitude, $data->longitude, $geofenceRadius);
        if (! $geofenceResult['passed']) {
            throw new OutsideGeofenceException('Subject is outside the approved work location geofence.');
        }

        $checkInAt = CarbonImmutable::parse($openSession->check_in_at)->utc();
        $checkOutAt = $data->occurredAt->utc();

        if ($checkOutAt->lessThanOrEqualTo($checkInAt)) {
            throw new InvalidLocationException('Check-out time must be after check-in time.');
        }

        $durationMinutes = (int) max(0, round($checkInAt->diffInMinutes($checkOutAt)));

        $scheduleResult = $this->resolveScheduleResult($subject, CarbonImmutable::parse($record->attendance_date));
        $policyResult = $this->resolvePolicyResult($subject, CarbonImmutable::parse($record->attendance_date), 'check_out_blocked');
        if (! $policyResult['allowed']) {
            throw new AttendanceBlockedByPolicyException($policyResult['reason']);
        }

        $isLate = false;
        $isEarlyCheckout = false;

        if (! ($subject instanceof Outsource)) {
            $scheduledStart = $this->resolveScheduledStart($scheduleResult['shift'], $checkInAt);
            $scheduledEnd = $this->resolveScheduledEnd($scheduleResult['shift'], $checkOutAt);
            $isLate = $this->lateDetectionRule->isLate($checkInAt, $scheduledStart);
            $isEarlyCheckout = $this->earlyCheckoutRule->isEarlyCheckout($checkOutAt, $scheduledEnd);
        }

        $openSession->update([
            'check_out_at' => $checkOutAt,
            'duration_minutes' => $durationMinutes,
            'status' => AttendanceSessionStatus::Closed->value,
        ]);

        $event = $this->createEvent($subject, $record, $openSession, $data);

        // Outsource daily status is only incomplete (open/expired) or present (closed).
        // Employee keeps late / early-checkout → late via AttendanceStateRule.
        $status = $this->attendanceStateRule->determine(
            hasPolicy: $policyResult['allowed'],
            hasSchedule: $scheduleResult['hasSchedule'],
            hasOpenSession: false,
            isLate: $isLate,
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

    private function resolvePolicyResult(AttendanceSubject $subject, CarbonImmutable $date, string $blockFlag): array
    {
        if ($subject instanceof Outsource) {
            return [
                'allowed' => true,
                'reason' => null,
                'policies' => [],
            ];
        }

        return $this->resolveAttendancePolicy($subject, $date, $blockFlag);
    }

    private function resolveScheduleResult(AttendanceSubject $subject, CarbonImmutable $date): array
    {
        if ($subject instanceof Outsource) {
            return [
                'hasSchedule' => true,
                'schedule' => null,
                'shift' => null,
                'assignment' => null,
            ];
        }

        return $this->resolveAttendanceSchedule($subject, $date);
    }

    private function evaluateGeofence(?WorkLocation $workLocation, ?float $latitude, ?float $longitude, ?float $radiusMeters = null): array
    {
        if ($workLocation === null || $latitude === null || $longitude === null) {
            return ['passed' => true, 'distance_meters' => null, 'method' => 'skipped'];
        }

        try {
            $this->geofenceRule->validate($workLocation, $latitude, $longitude, $radiusMeters);
            $distanceMeters = $this->queryGeofenceDistance($workLocation, $latitude, $longitude);

            return ['passed' => true, 'distance_meters' => $distanceMeters, 'method' => 'postgis'];
        } catch (AttendanceOutsideGeofenceException $e) {
            $distanceMeters = $this->queryGeofenceDistance($workLocation, $latitude, $longitude);

            return ['passed' => false, 'distance_meters' => $distanceMeters, 'method' => 'postgis'];
        }
    }

    private function validateOutsourceAssignment(Outsource $outsource, ?WorkLocation $workLocation): void
    {
        if ($workLocation === null) {
            throw new \InvalidArgumentException('Work location is required for outsource attendance.');
        }

        if ($outsource->status !== 'active' || $outsource->trashed()) {
            throw new InactiveSubjectException('Outsource is inactive.');
        }

        if ($workLocation->status !== 'active' || $workLocation->trashed()) {
            throw new \InvalidArgumentException('Work location is inactive.');
        }

        $assignment = OutsourceStoreAssignment::query()
            ->where('outsource_id', $outsource->id)
            ->where('store_id', $workLocation->id)
            ->where('status', 'active')
            ->first();

        if ($assignment === null) {
            throw new \InvalidArgumentException('Outsource is not assigned to this work location.');
        }
    }

    /**
     * Outsource: one session per clock-in date (open, closed, or expired).
     */
    private function assertOutsourceMayStartSession(AttendanceRecord $existingRecord, CarbonImmutable $now): void
    {
        $sessions = $existingRecord->sessions()->get();

        foreach ($sessions as $session) {
            if ($session->status === AttendanceSessionStatus::Open->value) {
                if ($this->outsourceSessionExpiry->expireIfPastLimit($session, $now)) {
                    throw new AttendanceDayAlreadyCompletedException(
                        'Outsource attendance for this clock-in date already expired without check-out.'
                    );
                }

                throw new AttendanceAlreadyCheckedInException('Subject already has an open attendance session.');
            }

            throw new AttendanceDayAlreadyCompletedException(
                'Outsource attendance for this clock-in date is already completed.'
            );
        }
    }

    private function assertOutsourceSessionStillCloseable(AttendanceSession $openSession, CarbonImmutable $at): void
    {
        if (! $this->outsourceSessionExpiry->expireIfPastLimit($openSession, $at)) {
            return;
        }

        $maxHours = $this->outsourceSessionExpiry->maxHours();

        throw new AttendanceSessionExpiredException(
            "Outsource attendance session exceeded the {$maxHours}-hour limit and is now incomplete."
        );
    }

    private function queryGeofenceDistance(WorkLocation $workLocation, float $latitude, float $longitude): ?float
    {
        try {
            return WorkLocation::where('id', $workLocation->id)
                ->selectRaw('ST_Distance(location_point::geography, ST_SetSRID(ST_MakePoint(?, ?), 4326)::geography) AS distance_meters', [$longitude, $latitude])
                ->value('distance_meters');
        } catch (\Throwable $e) {
            return null;
        }
    }

    private function resolveAttendancePolicy(AttendanceSubject $subject, CarbonImmutable $date, string $blockFlag): array
    {
        $employee = $subject instanceof Employee ? $subject : null;
        if ($employee === null) {
            return ['allowed' => true, 'reason' => null, 'policies' => []];
        }

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

    private function resolveAttendanceSchedule(AttendanceSubject $subject, CarbonImmutable $date): array
    {
        $employee = $subject instanceof Employee ? $subject : null;
        if ($employee === null) {
            return [
                'hasSchedule' => true,
                'schedule' => null,
                'shift' => null,
                'assignment' => null,
            ];
        }

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

    private function resolveWorkDate(CarbonImmutable $occurredAt, AttendanceSubject $subject): CarbonImmutable
    {
        $tz = AttendanceDateTime::timezone();
        $local = $occurredAt->timezone($tz);

        if ($subject instanceof Outsource) {
            return $local->startOfDay();
        }

        $employee = $subject;

        $scheduleResult = $this->resolveAttendanceSchedule($employee, $local);

        if ($scheduleResult['hasSchedule'] && $scheduleResult['shift'] !== null) {
            $shift = $scheduleResult['shift'];

            if ($shift->cross_midnight) {
                if ($local->format('H:i:s') < $shift->end_time) {
                    return $local->subDay()->startOfDay();
                }
            }
        }

        return $local->startOfDay();
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

        $tz = AttendanceDateTime::timezone();
        $local = $at->timezone($tz);
        $date = $local->toDateString();

        if ($shift->cross_midnight && $local->format('H:i:s') < $shift->end_time) {
            $date = $local->subDay()->toDateString();
        }

        return CarbonImmutable::createFromFormat('Y-m-d H:i:s', $date.' '.$shift->start_time, $tz);
    }

    private function resolveScheduledEnd(?Shift $shift, CarbonImmutable $at): ?CarbonImmutable
    {
        if ($shift === null) {
            return null;
        }

        $tz = AttendanceDateTime::timezone();
        $local = $at->timezone($tz);
        $date = $local->toDateString();

        if ($shift->cross_midnight && $local->format('H:i:s') < $shift->end_time) {
            $date = $local->addDay()->toDateString();
        }

        return CarbonImmutable::createFromFormat('Y-m-d H:i:s', $date.' '.$shift->end_time, $tz);
    }

    private function findExistingRecord(AttendanceSubject $subject, CarbonImmutable $date): ?AttendanceRecord
    {
        $query = AttendanceRecord::query()
            ->whereDate('attendance_date', $date->toDateString());

        if ($subject instanceof Employee) {
            $query->where('employee_id', $subject->getId());
        } else {
            $query->where('outsource_id', $subject->getId());
        }

        return $query->first();
    }

    private function createAttendanceRecord(AttendanceSubject $subject, CarbonImmutable $date): AttendanceRecord
    {
        $attributes = [
            'attendance_date' => $date->toDateString(),
            'status' => 'incomplete',
        ];

        if ($subject instanceof Employee) {
            $attributes['employee_id'] = $subject->getId();
            $attributes['attendable_type'] = 'employee';
        } else {
            $attributes['outsource_id'] = $subject->getId();
            $attributes['attendable_type'] = 'outsource';
        }

        return AttendanceRecord::create($attributes);
    }

    private function createSession(AttendanceRecord $record, CarbonImmutable $checkInAt): AttendanceSession
    {
        return AttendanceSession::create([
            'attendance_record_id' => $record->id,
            'check_in_at' => $checkInAt->utc(),
            'status' => AttendanceSessionStatus::Open->value,
        ]);
    }

    private function createEvent(
        AttendanceSubject $subject,
        AttendanceRecord $record,
        AttendanceSession $session,
        AttendanceOperationData $data,
    ): AttendanceEvent {
        $attributes = [
            'attendance_id' => $record->id,
            'attendance_session_id' => $session->id,
            'event_type' => $data->eventType->value,
            'occurred_at' => $data->occurredAt->utc(),
            'latitude' => $data->latitude,
            'longitude' => $data->longitude,
            'accuracy_meters' => $data->accuracy,
            'source' => $data->source,
            'device_metadata' => array_filter([
                'device_fingerprint' => $data->deviceIdentifier,
            ]),
        ];

        if ($subject instanceof Employee) {
            $attributes['employee_id'] = $subject->getId();
        } else {
            $attributes['outsource_id'] = $subject->getId();
        }

        return AttendanceEvent::create($attributes);
    }
}
