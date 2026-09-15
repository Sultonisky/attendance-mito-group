<?php

namespace App\Domain\MonthlyRecap\DTOs;

use Carbon\CarbonImmutable;

final readonly class MonthlyRecapData
{
    public function __construct(
        public int $employeeId,
        public CarbonImmutable $periodStart,
        public CarbonImmutable $periodEnd,
        public int $scheduledDays,
        public int $presentDays,
        public int $lateDays,
        public int $incompleteDays,
        public int $absentDays,
        public int $leaveDays,
        public int $businessTripDays,
        public int $overtimeApprovedMinutes,
        public int $overtimePotentialMinutes,
        public float $penaltyPoints,
        public int $penaltyCount,
        public array $details,
    ) {}
}
