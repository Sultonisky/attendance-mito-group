<?php

namespace App\Domain\MonthlyRecap\Engines;

use App\Domain\MonthlyRecap\DTOs\MonthlyRecapData;
use App\Domain\MonthlyRecap\DTOs\MonthlyRecapDetailData;
use App\Domain\MonthlyRecap\Exceptions\ScheduleEngineException;
use App\Domain\Schedule\Engines\ScheduleEngine;
use App\Models\AttendanceRecord;
use App\Models\Employee;
use App\Models\Outsource;
use Carbon\CarbonImmutable;

/**
 * Attendance-only monthly recap.
 *
 * Employee: scheduled days via ScheduleEngine + attendance_records.
 * Outsource: calendar days in period + attendance_records (no schedule/leave/OT/penalty).
 */
class MonthlyRecapEngine
{
    public function __construct(
        private ScheduleEngine $scheduleEngine,
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

    public function generateForOutsource(Outsource $outsource, CarbonImmutable $periodStart, CarbonImmutable $periodEnd): MonthlyRecapData
    {
        $this->assertPeriod($periodStart, $periodEnd);

        $scheduledDays = 0;
        $presentDays = 0;
        $lateDays = 0;
        $incompleteDays = 0;
        $absentDays = 0;

        $records = AttendanceRecord::query()
            ->where('outsource_id', $outsource->id)
            ->where('attendable_type', 'outsource')
            ->whereDate('attendance_date', '>=', $periodStart->toDateString())
            ->whereDate('attendance_date', '<=', $periodEnd->toDateString())
            ->get()
            ->keyBy(fn (AttendanceRecord $r) => CarbonImmutable::parse($r->attendance_date)->toDateString());

        for ($day = $periodStart; $day->lessThanOrEqualTo($periodEnd); $day = $day->addDay()) {
            $scheduledDays++;
            $key = $day->toDateString();
            $record = $records->get($key);

            if ($record === null) {
                $absentDays++;

                continue;
            }

            match ($record->status) {
                'present' => $presentDays++,
                'late' => $lateDays++,
                'incomplete' => $incompleteDays++,
                'absent' => $absentDays++,
                default => $absentDays++,
            };
        }

        return $this->buildData(
            source: 'outsource',
            employeeId: null,
            outsourceId: (int) $outsource->id,
            periodStart: $periodStart,
            periodEnd: $periodEnd,
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
