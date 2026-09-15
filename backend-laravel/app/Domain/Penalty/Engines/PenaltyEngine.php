<?php

namespace App\Domain\Penalty\Engines;

use App\Domain\Penalty\DTOs\PenaltyViolationData;
use App\Domain\Penalty\Exceptions\LeaveCheckException;
use App\Domain\Policy\Engines\PolicyEngine;
use App\Domain\Schedule\DTOs\ScheduleResolutionData;
use App\Domain\Schedule\Engines\ScheduleEngine;
use App\Enums\ApprovalStatus;
use App\Enums\PenaltyViolationType;
use App\Enums\PermissionRequestType;
use App\Enums\RecordStatus;
use App\Exceptions\Domain\DomainException;
use App\Models\AttendanceRecord;
use App\Models\Employee;
use App\Models\LeaveRequest;
use App\Models\PenaltyRule;
use App\Models\PermissionRequest;
use Carbon\CarbonImmutable;

class PenaltyEngine
{
    public function __construct(
        private PolicyEngine $policyEngine,
        private ScheduleEngine $scheduleEngine,
    ) {}

    /**
     * Evaluate attendance facts for an employee on a date and return
     * qualified penalty violations matched against active rules.
     *
     * @return list<PenaltyViolationData>
     */
    public function evaluate(Employee $employee, CarbonImmutable $date): array
    {
        try {
            $policyData = $this->policyEngine->resolve($employee, $date);
        } catch (\Throwable $e) {
            throw new DomainException(
                'Failed to resolve policy for penalty evaluation: '.$e->getMessage(),
                previous: $e instanceof \Exception ? $e : null
            );
        }

        try {
            $scheduleData = $this->scheduleEngine->resolve($employee, $date);
        } catch (\Throwable $e) {
            throw new DomainException(
                'Failed to resolve schedule for penalty evaluation: '.$e->getMessage(),
                previous: $e instanceof \Exception ? $e : null
            );
        }

        if (! $scheduleData->hasSchedule()) {
            return [];
        }

        $isOnLeave = $this->checkApprovedLeave($employee, $date);
        if ($isOnLeave) {
            return [];
        }

        $isOnBusinessTrip = $this->checkApprovedBusinessTrip($employee, $date);
        if ($isOnBusinessTrip) {
            return [];
        }

        $attendanceRecord = AttendanceRecord::where('employee_id', $employee->id)
            ->where('attendance_date', $date->toDateTimeString())
            ->first();

        $hasAttendance = $attendanceRecord !== null;
        $hasOpenSession = false;
        $firstCheckIn = null;
        $finalCheckOut = null;

        if ($hasAttendance) {
            $hasOpenSession = $attendanceRecord->sessions()
                ->where('status', 'open')
                ->exists();

            $firstSession = $attendanceRecord->sessions()
                ->whereNotNull('check_in_at')
                ->orderBy('check_in_at')
                ->first();
            if ($firstSession) {
                $firstCheckIn = CarbonImmutable::parse($firstSession->check_in_at);
            }

            $lastSession = $attendanceRecord->sessions()
                ->whereNotNull('check_out_at')
                ->orderByDesc('check_out_at')
                ->first();
            if ($lastSession) {
                $finalCheckOut = CarbonImmutable::parse($lastSession->check_out_at);
            }
        }

        $scheduledStart = $this->resolveScheduledStart($scheduleData, $date);
        $scheduledEnd = $this->resolveScheduledEnd($scheduleData, $date);

        $graceMinutes = $policyData->hasPolicy()
            ? ($policyData->policy->configuration['grace_period_minutes'] ?? null)
            : null;

        $violations = [];

        // LATE
        if ($hasAttendance && $firstCheckIn !== null && $scheduledStart !== null && $firstCheckIn->greaterThan($scheduledStart)) {
            $lateMinutes = $scheduledStart->diffInMinutes($firstCheckIn);
            if ($graceMinutes !== null && $graceMinutes > 0) {
                $lateMinutes = max(0, $lateMinutes - $graceMinutes);
            }
            if ($lateMinutes > 0) {
                $violations[] = new PenaltyViolationData(
                    employeeId: $employee->id,
                    date: $date,
                    violationType: PenaltyViolationType::Late,
                    violationValue: $lateMinutes,
                    rule: null,
                    points: 0,
                    attendanceId: $attendanceRecord->id,
                );
            }
        }

        // EARLY_CHECKOUT
        if ($hasAttendance && $finalCheckOut !== null && $scheduledEnd !== null && $finalCheckOut->lessThan($scheduledEnd)) {
            $earlyMinutes = $finalCheckOut->diffInMinutes($scheduledEnd);
            if ($graceMinutes !== null && $graceMinutes > 0) {
                $earlyMinutes = max(0, $earlyMinutes - $graceMinutes);
            }
            if ($earlyMinutes > 0) {
                $violations[] = new PenaltyViolationData(
                    employeeId: $employee->id,
                    date: $date,
                    violationType: PenaltyViolationType::EarlyCheckout,
                    violationValue: $earlyMinutes,
                    rule: null,
                    points: 0,
                    attendanceId: $attendanceRecord->id,
                );
            }
        }

        // ABSENCE
        if (! $hasAttendance) {
            $violations[] = new PenaltyViolationData(
                employeeId: $employee->id,
                date: $date,
                violationType: PenaltyViolationType::Absence,
                violationValue: null,
                rule: null,
                points: 0,
                attendanceId: null,
            );
        }

        // INCOMPLETE_ATTENDANCE
        if ($hasAttendance && $hasOpenSession) {
            $violations[] = new PenaltyViolationData(
                employeeId: $employee->id,
                date: $date,
                violationType: PenaltyViolationType::IncompleteAttendance,
                violationValue: null,
                rule: null,
                points: 0,
                attendanceId: $attendanceRecord->id,
            );
        }

        // Match active rules
        $activeRules = PenaltyRule::where('status', RecordStatus::Active->value)->get();
        $qualified = [];

        foreach ($violations as $violation) {
            foreach ($activeRules as $rule) {
                $ruleViolationType = $this->resolveRuleViolationType($rule);
                if ($ruleViolationType !== $violation->violationType) {
                    continue;
                }

                $threshold = $rule->threshold;
                if ($threshold !== null && $violation->violationValue !== null && (int) $violation->violationValue < (int) $threshold) {
                    continue;
                }

                $qualified[] = new PenaltyViolationData(
                    employeeId: $violation->employeeId,
                    date: $violation->date,
                    violationType: $violation->violationType,
                    violationValue: $violation->violationValue,
                    rule: $rule,
                    points: (float) $rule->points,
                    attendanceId: $violation->attendanceId,
                );
            }
        }

        return $qualified;
    }

    private function checkApprovedLeave(Employee $employee, CarbonImmutable $date): bool
    {
        try {
            return LeaveRequest::where('employee_id', $employee->id)
                ->where('start_date', '<=', $date->toDateString())
                ->where('end_date', '>=', $date->toDateString())
                ->where('status', ApprovalStatus::Approved->value)
                ->exists();
        } catch (\Throwable $e) {
            throw new LeaveCheckException(
                'Unable to verify leave status for employee '.$employee->id.' on '.$date->toDateString().'.',
                previous: $e instanceof \Exception ? $e : null
            );
        }
    }

    private function checkApprovedBusinessTrip(Employee $employee, CarbonImmutable $date): bool
    {
        try {
            return PermissionRequest::where('employee_id', $employee->id)
                ->where('permission_type', PermissionRequestType::Business->value)
                ->where('status', ApprovalStatus::Approved->value)
                ->where('start_at', '<=', $date->endOfDay())
                ->where('end_at', '>=', $date->startOfDay())
                ->exists();
        } catch (\Throwable $e) {
            throw new LeaveCheckException(
                'Unable to verify business trip status for employee '.$employee->id.' on '.$date->toDateString().'.',
                previous: $e instanceof \Exception ? $e : null
            );
        }
    }

    private function resolveRuleViolationType(PenaltyRule $rule): ?PenaltyViolationType
    {
        $configured = $rule->configuration['violation_type'] ?? null;
        if ($configured) {
            return PenaltyViolationType::from($configured);
        }

        $code = strtolower($rule->code ?? '');

        return match (true) {
            str_contains($code, 'late') => PenaltyViolationType::Late,
            str_contains($code, 'early') && str_contains($code, 'checkout') => PenaltyViolationType::EarlyCheckout,
            str_contains($code, 'absence') => PenaltyViolationType::Absence,
            str_contains($code, 'incomplete') => PenaltyViolationType::IncompleteAttendance,
            default => null,
        };
    }

    private function resolveScheduledStart(ScheduleResolutionData $schedule, CarbonImmutable $date): ?CarbonImmutable
    {
        if (! $schedule->hasSchedule()) {
            return null;
        }

        $shift = $schedule->schedule->shifts->first();
        if (! $shift) {
            return null;
        }

        $dateStr = $date->toDateString();
        if ($shift->cross_midnight && $date->format('H:i:s') < $shift->end_time) {
            $dateStr = $date->subDay()->toDateString();
        }

        return CarbonImmutable::createFromFormat('Y-m-d H:i:s', $dateStr.' '.$shift->start_time);
    }

    private function resolveScheduledEnd(ScheduleResolutionData $schedule, CarbonImmutable $date): ?CarbonImmutable
    {
        if (! $schedule->hasSchedule()) {
            return null;
        }

        $shift = $schedule->schedule->shifts->first();
        if (! $shift) {
            return null;
        }

        $dateStr = $date->toDateString();
        if ($shift->cross_midnight && $date->format('H:i:s') < $shift->end_time) {
            $dateStr = $date->addDay()->toDateString();
        }

        return CarbonImmutable::createFromFormat('Y-m-d H:i:s', $dateStr.' '.$shift->end_time);
    }
}
