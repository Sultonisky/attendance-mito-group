<?php

namespace App\Domain\MonthlyRecap\Engines;

use App\Domain\MonthlyRecap\DTOs\MonthlyRecapData;
use App\Domain\MonthlyRecap\DTOs\MonthlyRecapDetailData;
use App\Domain\MonthlyRecap\Exceptions\ScheduleEngineException;
use App\Domain\Schedule\Engines\ScheduleEngine;
use App\Enums\AttendanceSessionStatus;
use App\Models\AttendanceRecord;
use App\Models\Employee;
use App\Models\Outsource;
use App\Services\Outsource\CountedOutsourceAttendance;
use App\Support\OutsourceAttendancePeriod;
use Carbon\CarbonImmutable;

/**
 * Attendance-only monthly recap.
 *
 * Employee: calendar month, scheduled days via ScheduleEngine + attendance_records.
 * Outsource: cutoff period (25 .. 24) capped at N counted days, identical to the
 * outsource history (no schedule/leave/OT/penalty).
 */
class MonthlyRecapEngine
{
    public function __construct(
        private ScheduleEngine $scheduleEngine,
        private CountedOutsourceAttendance $countedAttendance,
    ) {}

    public function generate(Employee $employee, CarbonImmutable $periodStart, CarbonImmutable $periodEnd): MonthlyRecapData
    {
        $this->assertPeriod($periodStart, $periodEnd);

        $scheduledDays = 0;
        $presentDays = 0;
        $lateDays = 0;
        $incompleteDays = 0;

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

            $record = AttendanceRecord::query()
                ->where('employee_id', $employee->id)
                ->where('attendable_type', 'employee')
                ->whereDate('attendance_date', $day->toDateString())
                ->first();

            if ($record === null) {
                continue;
            }

            match ($record->status) {
                'present' => $presentDays++,
                'late' => $lateDays++,
                'incomplete' => $incompleteDays++,
                default => null,
            };
        }

        $absentDays = max(0, $scheduledDays - $presentDays - $lateDays - $incompleteDays);

        return $this->buildData(
            source: 'employee',
            employeeId: (int) $employee->id,
            outsourceId: null,
            periodStart: $periodStart,
            periodEnd: $periodEnd,
            scheduledDays: $scheduledDays,
            presentDays: $presentDays,
            lateDays: $lateDays,
            incompleteDays: $incompleteDays,
            absentDays: $absentDays,
        );
    }

    /**
     * Outsource recap over the cutoff period (e.g. 25 Aug .. 24 Sep) with the same
     * counted-days rule as the outsource history: first N days with a clock-in
     * (N = max attendance days, default 26). Scheduled = N, absent = N - attended.
     */
    public function generateForOutsource(Outsource $outsource, OutsourceAttendancePeriod $period): MonthlyRecapData
    {
        $scheduledDays = OutsourceAttendancePeriod::maxAttendanceDays();
        $presentDays = 0;
        $lateDays = 0;
        $incompleteDays = 0;

        $records = $this->countedAttendance->query((int) $outsource->id, $period)
            ->with('sessions:id,attendance_record_id,status')
            ->get();

        foreach ($records as $record) {
            $hasOpen = $record->sessions->contains(
                fn ($session) => $session->status === AttendanceSessionStatus::Open->value
            );

            if ($record->status === 'incomplete' || $hasOpen) {
                $incompleteDays++;
            } elseif ($record->status === 'late') {
                $lateDays++;
            } else {
                $presentDays++;
            }
        }

        $absentDays = max(0, $scheduledDays - $presentDays - $lateDays - $incompleteDays);

        return $this->buildData(
            source: 'outsource',
            employeeId: null,
            outsourceId: (int) $outsource->id,
            periodStart: $period->startDate,
            periodEnd: $period->endDate,
            scheduledDays: $scheduledDays,
            presentDays: $presentDays,
            lateDays: $lateDays,
            incompleteDays: $incompleteDays,
            absentDays: $absentDays,
        );
    }

    private function assertPeriod(CarbonImmutable $periodStart, CarbonImmutable $periodEnd): void
    {
        if ($periodEnd->lessThan($periodStart)) {
            throw new \InvalidArgumentException('Period end must be greater than or equal to period start.');
        }
    }

    private function buildData(
        string $source,
        ?int $employeeId,
        ?int $outsourceId,
        CarbonImmutable $periodStart,
        CarbonImmutable $periodEnd,
        int $scheduledDays,
        int $presentDays,
        int $lateDays,
        int $incompleteDays,
        int $absentDays,
    ): MonthlyRecapData {
        $details = [
            new MonthlyRecapDetailData(detailType: 'attendance', category: 'scheduled_days', quantity: $scheduledDays),
            new MonthlyRecapDetailData(detailType: 'attendance', category: 'present_days', quantity: $presentDays),
            new MonthlyRecapDetailData(detailType: 'attendance', category: 'late_days', quantity: $lateDays),
            new MonthlyRecapDetailData(detailType: 'attendance', category: 'incomplete_days', quantity: $incompleteDays),
            new MonthlyRecapDetailData(detailType: 'attendance', category: 'absent_days', quantity: $absentDays),
        ];

        return new MonthlyRecapData(
            source: $source,
            employeeId: $employeeId,
            outsourceId: $outsourceId,
            periodStart: $periodStart,
            periodEnd: $periodEnd,
            scheduledDays: $scheduledDays,
            presentDays: $presentDays,
            lateDays: $lateDays,
            incompleteDays: $incompleteDays,
            absentDays: $absentDays,
            details: $details,
        );
    }
}
