<?php

namespace App\Domain\Overtime\DTOs;

use App\Models\AttendanceRecord;
use App\Models\Employee;
use Carbon\CarbonImmutable;

/**
 * Input data for overtime calculation.
 */
final readonly class OvertimeCalculationData
{
    public function __construct(
        public Employee $employee,
        public CarbonImmutable $date,
        public ?AttendanceRecord $attendanceRecord,
        public array $closedSessions,
        public ?CarbonImmutable $scheduledEnd,
        public bool $isOnLeave,
        public bool $isActive,
    ) {}
}
