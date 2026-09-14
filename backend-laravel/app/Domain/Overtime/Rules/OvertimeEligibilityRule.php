<?php

namespace App\Domain\Overtime\Rules;

use App\Domain\Overtime\Exceptions\InactiveEmployeeException;
use App\Domain\Overtime\Exceptions\IncompleteAttendanceException;
use App\Domain\Overtime\Exceptions\MissingAttendanceException;
use App\Domain\Overtime\Exceptions\MissingScheduleException;
use App\Enums\AttendanceStatus;
use App\Models\AttendanceRecord;
use App\Models\Employee;
use Carbon\CarbonImmutable;

/**
 * Determines whether an employee is eligible for overtime on a given date.
 */
class OvertimeEligibilityRule
{
    public function assertEligible(Employee $employee, CarbonImmutable $date, ?AttendanceRecord $record, ?array $closedSessions, ?bool $hasSchedule): void
    {
        if ($employee->end_date && $employee->end_date->isPast()) {
            throw new InactiveEmployeeException('Employee has ended employment.');
        }

        if ($record === null) {
            throw new MissingAttendanceException('No attendance record found for the date.');
        }

        if ($hasSchedule === false) {
            throw new MissingScheduleException('No schedule assigned for the date.');
        }

        if (empty($closedSessions)) {
            throw new IncompleteAttendanceException('Attendance record has no closed sessions.');
        }

        $status = $record->status;
        if ($status === AttendanceStatus::OffDay->value
            || $status === AttendanceStatus::Holiday->value
            || $status === AttendanceStatus::Leave->value
            || $status === AttendanceStatus::Absent->value
        ) {
            throw new IncompleteAttendanceException('Overtime is not eligible for '.$status.' days.');
        }
    }
}
