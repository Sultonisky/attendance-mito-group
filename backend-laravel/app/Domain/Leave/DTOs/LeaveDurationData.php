<?php

namespace App\Domain\Leave\DTOs;

use Carbon\CarbonImmutable;

/**
 * Immutable leave duration for a calendar-date range (inclusive).
 */
final readonly class LeaveDurationData
{
    public function __construct(
        public CarbonImmutable $startDate,
        public CarbonImmutable $endDate,
        public int $totalDays,
    ) {}
}
