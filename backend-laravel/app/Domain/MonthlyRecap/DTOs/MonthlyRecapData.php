<?php

namespace App\Domain\MonthlyRecap\DTOs;

use Carbon\CarbonImmutable;

final readonly class MonthlyRecapData
{
    /**
     * @param  list<MonthlyRecapDetailData>  $details
     */
    public function __construct(
        public string $source,
        public ?int $employeeId,
        public ?int $outsourceId,
        public CarbonImmutable $periodStart,
        public CarbonImmutable $periodEnd,
        public int $scheduledDays,
        public int $presentDays,
        public int $lateDays,
        public int $incompleteDays,
        public int $absentDays,
        public array $details,
    ) {}
}
