<?php

namespace App\Domain\Overtime\DTOs;

use Carbon\CarbonImmutable;

/**
 * Duration breakdown for an overtime interval.
 */
final readonly class OvertimeDurationData
{
    public function __construct(
        public CarbonImmutable $scheduledEnd,
        public CarbonImmutable $latestCheckOut,
        public int $minutes,
    ) {}
}
