<?php

namespace App\Domain\Overtime\DTOs;

use Carbon\CarbonImmutable;

/**
 * Output of overtime calculation.
 */
final readonly class OvertimeResultData
{
    public function __construct(
        public int $employeeId,
        public CarbonImmutable $date,
        public bool $eligible,
        public ?string $reason,
        public ?int $potentialMinutes,
        public ?CarbonImmutable $scheduledEnd,
        public ?CarbonImmutable $latestCheckOut,
    ) {}
}
