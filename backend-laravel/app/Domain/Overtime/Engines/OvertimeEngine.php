<?php

namespace App\Domain\Overtime\Engines;

use App\Domain\Leave\Engines\LeaveEngine;
use App\Domain\Overtime\DTOs\OvertimeCalculationData;
use App\Domain\Overtime\DTOs\OvertimeDurationData;
use App\Domain\Overtime\DTOs\OvertimeResultData;
use App\Domain\Overtime\Exceptions\InactiveEmployeeException;
use App\Domain\Overtime\Exceptions\LeaveEngineException;
use App\Domain\Overtime\Rules\OvertimeEligibilityRule;
use App\Domain\Policy\Engines\PolicyEngine;
use App\Domain\Schedule\Engines\ScheduleEngine;
use App\Enums\AttendanceSessionStatus;
use App\Models\AttendanceRecord;
use App\Models\Employee;
use App\Models\WorkSchedule;
use Carbon\CarbonImmutable;

/**
 * Authoritative overtime domain decisions.
 *
 * Overtime is derived from actual attendance facts and applicable
 * policy/schedule. The same input facts must produce the same result.
 *
 * Calculation model:
 *   - Employee must be active.
 *   - A schedule must be assigned for the date.
 *   - Attendance must exist and have at least one closed session.
 *   - Days with status OffDay, Holiday, Leave, or Absent are not eligible.
 *   - Approved leave for the date disqualifies overtime.
 *   - Overtime candidate = latest closed checkout - scheduled end (if positive).
 *   - Multiple sessions: only the latest closed checkout is used.
 *   - Open/incomplete sessions do not generate overtime.
 */
class OvertimeEngine
{
    public function __construct(
        private OvertimeEligibilityRule $eligibilityRule,
        private LeaveEngine $leaveEngine,
    ) {}

    /**
     * Calculate overtime for the employee on the given date.
     */
    public function calculateForDate(Employee $employee, CarbonImmutable $date): OvertimeResultData
    {
        if ($employee->end_date && $employee->end_date->isPast()) {
            throw new InactiveEmployeeException('Employee has ended employment.');
        }

        $record = AttendanceRecord::where('employee_id', $employee->id)
            ->whereDate('attendance_date', $date->toDateString())
            ->first();

        $hasSchedule = false;
        $scheduledEnd = null;

        $policyData = null;
        $scheduleData = null;

        try {
            $policyData = app(PolicyEngine::class)->resolve($employee, $date);
        } catch (\Throwable) {
            // Policy resolution failure is non-fatal for overtime eligibility.
        }

        try {
            $scheduleData = app(ScheduleEngine::class)->resolve($employee, $date);
            $hasSchedule = $scheduleData->hasSchedule();
            if ($hasSchedule) {
                $scheduledEnd = $this->resolveScheduledEnd($scheduleData->schedule, $date);
            }
        } catch (\Throwable) {
            $hasSchedule = false;
        }

        $closedSessions = [];
        if ($record !== null) {
            $closedSessions = $record->sessions()
                ->where('status', AttendanceSessionStatus::Closed->value)
                ->orderByDesc('check_out_at')
                ->get()
                ->all();
        }

        $isOnLeave = false;
        try {
            $leaveResolution = $this->leaveEngine->resolveForDate($employee, $date);
            $isOnLeave = $leaveResolution->onApprovedLeave;
        } catch (\Throwable $e) {
            throw new LeaveEngineException('Failed to resolve leave status for overtime calculation.', previous: $e);
        }

        $calculation = new OvertimeCalculationData(
            employee: $employee,
            date: $date,
            attendanceRecord: $record,
            closedSessions: $closedSessions,
            scheduledEnd: $scheduledEnd,
            isOnLeave: $isOnLeave,
            isActive: true,
        );

        try {
            $this->eligibilityRule->assertEligible($employee, $date, $record, $closedSessions, $hasSchedule);
        } catch (\Throwable $e) {
            return new OvertimeResultData(
                employeeId: $employee->id,
                date: $date,
                eligible: false,
                reason: $e->getMessage(),
                potentialMinutes: null,
                scheduledEnd: $scheduledEnd,
                latestCheckOut: null,
            );
        }

        if ($isOnLeave) {
            return new OvertimeResultData(
                employeeId: $employee->id,
                date: $date,
                eligible: false,
                reason: 'Employee is on approved leave.',
                potentialMinutes: null,
                scheduledEnd: $scheduledEnd,
                latestCheckOut: null,
            );
        }

        $latestSession = $closedSessions[0] ?? null;
        $latestCheckOut = $latestSession !== null ? CarbonImmutable::parse($latestSession->check_out_at) : null;

        if ($scheduledEnd === null || $latestCheckOut === null) {
            return new OvertimeResultData(
                employeeId: $employee->id,
                date: $date,
                eligible: false,
                reason: 'Unable to resolve scheduled end or checkout time.',
                potentialMinutes: null,
                scheduledEnd: $scheduledEnd,
                latestCheckOut: $latestCheckOut,
            );
        }

        $duration = $this->calculateDuration($scheduledEnd, $latestCheckOut);
        $potentialMinutes = $duration->minutes;

        return new OvertimeResultData(
            employeeId: $employee->id,
            date: $date,
            eligible: true,
            reason: $potentialMinutes > 0 ? null : 'No overtime beyond scheduled end.',
            potentialMinutes: $potentialMinutes,
            scheduledEnd: $scheduledEnd,
            latestCheckOut: $latestCheckOut,
        );
    }

    /**
     * Calculate the overtime duration between scheduled end and checkout.
     */
    public function calculateDuration(CarbonImmutable $scheduledEnd, CarbonImmutable $latestCheckOut): OvertimeDurationData
    {
        if ($latestCheckOut->lessThanOrEqualTo($scheduledEnd)) {
            return new OvertimeDurationData(
                scheduledEnd: $scheduledEnd,
                latestCheckOut: $latestCheckOut,
                minutes: 0,
            );
        }

        return new OvertimeDurationData(
            scheduledEnd: $scheduledEnd,
            latestCheckOut: $latestCheckOut,
            minutes: (int) $scheduledEnd->diffInMinutes($latestCheckOut),
        );
    }

    /**
     * Resolve the scheduled end time for a schedule on a given date.
     */
    private function resolveScheduledEnd(WorkSchedule $schedule, CarbonImmutable $date): ?CarbonImmutable
    {
        $shift = $schedule->shifts->first();

        if ($shift === null) {
            return null;
        }

        $dateStr = $date->toDateString();

        if ($shift->cross_midnight && $date->format('H:i:s') < $shift->end_time) {
            $dateStr = $date->addDay()->toDateString();
        }

        return CarbonImmutable::createFromFormat('Y-m-d H:i:s', $dateStr.' '.$shift->end_time);
    }
}
