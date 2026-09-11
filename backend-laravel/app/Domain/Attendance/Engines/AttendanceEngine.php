<?php

namespace App\Domain\Attendance\Engines;

use App\Domain\Attendance\DTOs\AttendanceOperationData;
use App\Domain\Attendance\DTOs\AttendanceResultData;
use App\Domain\Attendance\Exceptions\AttendanceAlreadyCheckedInException;
use App\Domain\Attendance\Exceptions\InvalidLocationException;
use App\Domain\Attendance\Exceptions\NoOpenAttendanceSessionException;
use App\Domain\Attendance\Rules\AttendanceStateRule;
use App\Domain\Attendance\Rules\EarlyCheckoutRule;
use App\Domain\Attendance\Rules\GeofenceRule;
use App\Domain\Attendance\Rules\GpsValidationRule;
use App\Domain\Attendance\Rules\LateDetectionRule;
use App\Domain\Policy\DTOs\PolicyResolutionData;
use App\Domain\Policy\Engines\PolicyEngine;
use App\Domain\Schedule\DTOs\ScheduleResolutionData;
use App\Domain\Schedule\Engines\ScheduleEngine;
use App\Enums\AttendanceEventType;
use App\Enums\AttendanceSessionStatus;
use App\Enums\VerificationStatus;
use App\Enums\VerificationType;
use App\Exceptions\Domain\InactiveEmployeeException;
use App\Models\AttendanceEvent;
use App\Models\AttendanceRecord;
use App\Models\AttendanceSession;
use App\Models\AttendanceVerification;
use App\Models\Employee;
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

        $policy = $this->resolvePolicy($employee, $date);
        $schedule = $this->resolveSchedule($employee, $date);

        $workLocation = $this->resolveWorkLocation($data->workLocationId);
        $this->geofenceRule->validate($workLocation, $data->latitude, $data->longitude);

        $existingRecord = AttendanceRecord::where('employee_id', $employee->id)
            ->where('attendance_date', $date->toDateTimeString())
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
        $verification = $this->createVerification($employee, $record, $session, $data, $workLocation);

        $scheduledStart = $this->resolveScheduledStart($schedule, $data->occurredAt);
        $isLate = $this->lateDetectionRule->isLate($data->occurredAt, $scheduledStart);

        $status = $this->attendanceStateRule->determine(
            hasPolicy: $policy->hasPolicy(),
            hasSchedule: $schedule->hasSchedule(),
            hasOpenSession: true,
            isLate: $isLate,
            isEarlyCheckout: false,
        );

        $record->update(['status' => $status->value]);

        return new AttendanceResultData(
            attendanceRecord: $record->fresh(),
            session: $session,
            verification: $verification,
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
        $this->geofenceRule->validate($workLocation, $data->latitude, $data->longitude);

        $checkInAt = CarbonImmutable::parse($openSession->check_in_at);
        $checkOutAt = $data->occurredAt;

        if ($checkOutAt->lessThanOrEqualTo($checkInAt)) {
            throw new InvalidLocationException('Check-out time must be after check-in time.');
        }

        $durationMinutes = (int) $checkInAt->diffInMinutes($checkOutAt);

        $schedule = $this->resolveSchedule($employee, CarbonImmutable::parse($record->attendance_date));
        $scheduledEnd = $this->resolveScheduledEnd($schedule, $checkOutAt);
        $isEarlyCheckout = $this->earlyCheckoutRule->isEarlyCheckout($checkOutAt, $scheduledEnd);

        $openSession->update([
            'check_out_at' => $checkOutAt,
            'duration_minutes' => $durationMinutes,
            'status' => AttendanceSessionStatus::Closed->value,
        ]);

        $event = AttendanceEvent::create([
            'employee_id' => $employee->id,
            'attendance_id' => $record->id,
            'attendance_session_id' => $openSession->id,
            'event_type' => AttendanceEventType::CheckOut->value,
            'occurred_at' => $checkOutAt,
            'latitude' => $data->latitude,
            'longitude' => $data->longitude,
            'accuracy_meters' => $data->accuracy,
            'source' => $data->source,
            'device_metadata' => [],
        ]);

        $verification = AttendanceVerification::create([
            'attendance_id' => $record->id,
            'attendance_session_id' => $openSession->id,
            'employee_id' => $employee->id,
            'verification_type' => VerificationType::Geofence->value,
            'status' => VerificationStatus::Passed->value,
            'verified_at' => $checkOutAt,
            'details' => [
                'latitude' => $data->latitude,
                'longitude' => $data->longitude,
                'work_location_id' => $workLocation->id,
                'work_location_name' => $workLocation->name,
            ],
        ]);

        $policy = $this->resolvePolicy($employee, CarbonImmutable::parse($record->attendance_date));
        $hasOpenSession = false;

        $status = $this->attendanceStateRule->determine(
            hasPolicy: $policy->hasPolicy(),
            hasSchedule: $schedule->hasSchedule(),
            hasOpenSession: $hasOpenSession,
            isLate: false,
            isEarlyCheckout: $isEarlyCheckout,
        );

        $record->update(['status' => $status->value]);

        return new AttendanceResultData(
            attendanceRecord: $record->fresh(),
            session: $openSession->fresh(),
            verification: $verification,
            message: 'Check-out recorded successfully.',
        );
    }

    private function resolvePolicy(Employee $employee, CarbonImmutable $date): PolicyResolutionData
    {
        return $this->policyEngine->resolve($employee, $date);
    }

    private function resolveSchedule(Employee $employee, CarbonImmutable $date): ScheduleResolutionData
    {
        return $this->scheduleEngine->resolve($employee, $date);
    }

    private function resolveWorkDate(CarbonImmutable $occurredAt, Employee $employee): CarbonImmutable
    {
        $schedule = $this->scheduleEngine->resolve($employee, $occurredAt);

        if ($schedule->hasSchedule()) {
            $shift = $schedule->schedule->shifts->first();

            if ($shift && $shift->cross_midnight) {
                $endTime = CarbonImmutable::createFromFormat('H:i:s', $shift->end_time);

                if ($occurredAt->format('H:i:s') < $endTime->format('H:i:s')) {
                    return $occurredAt->subDay();
                }
            }
        }

        return $occurredAt;
    }

    private function resolveWorkLocation(?int $workLocationId): WorkLocation
    {
        if ($workLocationId) {
            return WorkLocation::findOrFail($workLocationId);
        }

        return WorkLocation::where('status', 'active')->firstOrFail();
    }

    private function resolveScheduledStart(ScheduleResolutionData $schedule, CarbonImmutable $at): ?CarbonImmutable
    {
        if (! $schedule->hasSchedule()) {
            return null;
        }

        $shift = $schedule->schedule->shifts->first();

        if (! $shift) {
            return null;
        }

        $date = $at->toDateString();

        if ($shift->cross_midnight && $at->format('H:i:s') < $shift->end_time) {
            $date = $at->subDay()->toDateString();
        }

        return CarbonImmutable::createFromFormat('Y-m-d H:i:s', $date.' '.$shift->start_time);
    }

    private function resolveScheduledEnd(ScheduleResolutionData $schedule, CarbonImmutable $at): ?CarbonImmutable
    {
        if (! $schedule->hasSchedule()) {
            return null;
        }

        $shift = $schedule->schedule->shifts->first();

        if (! $shift) {
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
            'attendance_date' => $date->toDateTimeString(),
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

    private function createVerification(
        Employee $employee,
        AttendanceRecord $record,
        AttendanceSession $session,
        AttendanceOperationData $data,
        WorkLocation $workLocation,
    ): AttendanceVerification {
        return AttendanceVerification::create([
            'attendance_id' => $record->id,
            'attendance_session_id' => $session->id,
            'employee_id' => $employee->id,
            'verification_type' => VerificationType::Geofence->value,
            'status' => VerificationStatus::Passed->value,
            'verified_at' => $data->occurredAt,
            'details' => [
                'latitude' => $data->latitude,
                'longitude' => $data->longitude,
                'accuracy_meters' => $data->accuracy,
                'work_location_id' => $workLocation->id,
                'work_location_name' => $workLocation->name,
            ],
        ]);
    }
}
