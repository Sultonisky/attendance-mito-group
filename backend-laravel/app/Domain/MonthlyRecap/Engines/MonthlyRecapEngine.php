<?php

namespace App\Domain\MonthlyRecap\Engines;

use App\Domain\Leave\Engines\LeaveEngine;
use App\Domain\MonthlyRecap\DTOs\MonthlyRecapData;
use App\Domain\MonthlyRecap\DTOs\MonthlyRecapDetailData;
use App\Domain\MonthlyRecap\Exceptions\LeaveEngineException;
use App\Domain\MonthlyRecap\Exceptions\MonthlyRecapException;
use App\Domain\MonthlyRecap\Exceptions\ScheduleEngineException;
use App\Domain\Schedule\Engines\ScheduleEngine;
use App\Enums\ApprovalStatus;
use App\Enums\PermissionRequestType;
use App\Models\AttendanceRecord;
use App\Models\Employee;
use App\Models\OvertimeRecord;
use App\Models\PenaltyRecord;
use App\Models\PermissionRequest;
use Carbon\CarbonImmutable;

class MonthlyRecapEngine
{
    public function __construct(
        private LeaveEngine $leaveEngine,
        private ScheduleEngine $scheduleEngine,
    ) {}

    public function generate(Employee $employee, CarbonImmutable $periodStart, CarbonImmutable $periodEnd): MonthlyRecapData
    {
        if ($periodEnd->lessThan($periodStart)) {
            throw new \InvalidArgumentException('Period end must be greater than or equal to period start.');
        }

        $scheduledDays = 0;
        $presentDays = 0;
        $lateDays = 0;
        $incompleteDays = 0;
        $leaveDays = 0;
        $businessTripDays = 0;

        for ($day = $periodStart; $day->lessThanOrEqualTo($periodEnd); $day = $day->addDay()) {
            try {
                $scheduleData = $this->scheduleEngine->resolve($employee, $day);
            } catch (\Throwable $e) {
                throw new ScheduleEngineException('Failed to resolve schedule for '.$day->toDateString().': '.$e->getMessage(), previous: $e);
            }

            if (! $scheduleData->hasSchedule()) {
                continue;
            }
            $scheduledDays++;

            $onLeave = false;
            try {
                $leaveResolution = $this->leaveEngine->resolveForDate($employee, $day);
                $onLeave = $leaveResolution->onApprovedLeave;
            } catch (\Throwable $e) {
                throw new LeaveEngineException('Failed to resolve leave for '.$day->toDateString().': '.$e->getMessage(), previous: $e);
            }
            if ($onLeave) {
                $leaveDays++;

                continue;
            }

            $onBusinessTrip = false;
            try {
                $onBusinessTrip = $this->checkApprovedBusinessTrip($employee, $day);
            } catch (\Throwable $e) {
                throw new MonthlyRecapException('Failed to resolve business trip for '.$day->toDateString().': '.$e->getMessage(), previous: $e);
            }
            if ($onBusinessTrip) {
                $businessTripDays++;

                continue;
            }

            $record = AttendanceRecord::where('employee_id', $employee->id)
                ->whereDate('attendance_date', $day->toDateString())
                ->first();

            if ($record === null) {
                continue;
            }

            switch ($record->status) {
                case 'present':
                    $presentDays++;
                    break;
                case 'late':
                    $lateDays++;
                    break;
                case 'incomplete':
                    $incompleteDays++;
                    break;
                case 'absent':
                    break;
            }
        }

        $attendedDays = $presentDays + $lateDays + $incompleteDays;
        $absentDays = max(0, $scheduledDays - $attendedDays - $leaveDays - $businessTripDays);

        $overtimeRecords = OvertimeRecord::where('employee_id', $employee->id)
            ->whereDate('date', '>=', $periodStart->toDateString())
            ->whereDate('date', '<=', $periodEnd->toDateString())
            ->get();

        $overtimeApprovedMinutes = 0;
        $overtimePotentialMinutes = 0;
        foreach ($overtimeRecords as $otRecord) {
            if ($otRecord->status === 'approved') {
                $overtimeApprovedMinutes += (int) ($otRecord->approved_minutes ?? 0);
            }
            if (in_array($otRecord->status, ['potential', 'approved', 'actual'], true)) {
                $overtimePotentialMinutes += (int) ($otRecord->potential_minutes ?? 0);
            }
        }

        $penaltyRecords = PenaltyRecord::where('employee_id', $employee->id)
            ->where('occurred_at', '>=', $periodStart->startOfDay())
            ->where('occurred_at', '<=', $periodEnd->endOfDay())
            ->whereIn('status', ['applied', 'adjusted'])
            ->get();

        $penaltyPoints = 0.0;
        $penaltyCount = 0;
        foreach ($penaltyRecords as $penalty) {
            $penaltyPoints += (float) ($penalty->final_points ?? 0);
            $penaltyCount++;
        }

        $details = [
            new MonthlyRecapDetailData(detailType: 'attendance', category: 'scheduled_days', quantity: $scheduledDays),
            new MonthlyRecapDetailData(detailType: 'attendance', category: 'present_days', quantity: $presentDays),
            new MonthlyRecapDetailData(detailType: 'attendance', category: 'late_days', quantity: $lateDays),
            new MonthlyRecapDetailData(detailType: 'attendance', category: 'incomplete_days', quantity: $incompleteDays),
            new MonthlyRecapDetailData(detailType: 'attendance', category: 'absent_days', quantity: $absentDays),
            new MonthlyRecapDetailData(detailType: 'attendance', category: 'leave_days', quantity: $leaveDays),
            new MonthlyRecapDetailData(detailType: 'attendance', category: 'business_trip_days', quantity: $businessTripDays),
            new MonthlyRecapDetailData(detailType: 'overtime', category: 'approved_minutes', value: (float) $overtimeApprovedMinutes, quantity: $overtimeApprovedMinutes),
            new MonthlyRecapDetailData(detailType: 'overtime', category: 'potential_minutes', value: (float) $overtimePotentialMinutes, quantity: $overtimePotentialMinutes),
            new MonthlyRecapDetailData(detailType: 'penalty', category: 'points', value: (float) $penaltyPoints, quantity: $penaltyCount),
        ];

        return new MonthlyRecapData(
            employeeId: $employee->id,
            periodStart: $periodStart,
            periodEnd: $periodEnd,
            scheduledDays: $scheduledDays,
            presentDays: $presentDays,
            lateDays: $lateDays,
            incompleteDays: $incompleteDays,
            absentDays: $absentDays,
            leaveDays: $leaveDays,
            businessTripDays: $businessTripDays,
            overtimeApprovedMinutes: $overtimeApprovedMinutes,
            overtimePotentialMinutes: $overtimePotentialMinutes,
            penaltyPoints: (float) $penaltyPoints,
            penaltyCount: $penaltyCount,
            details: $details,
        );
    }

    private function checkApprovedBusinessTrip(Employee $employee, CarbonImmutable $date): bool
    {
        return PermissionRequest::where('employee_id', $employee->id)
            ->where('permission_type', PermissionRequestType::Business->value)
            ->where('status', ApprovalStatus::Approved->value)
            ->where('start_at', '<=', $date->endOfDay())
            ->where('end_at', '>=', $date->startOfDay())
            ->exists();
    }
}
