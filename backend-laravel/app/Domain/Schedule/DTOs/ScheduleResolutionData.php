<?php

namespace App\Domain\Schedule\DTOs;

use App\Models\WorkSchedule;
use Carbon\CarbonImmutable;

/**
 * Immutable result of schedule resolution for an employee on a specific date.
 */
final readonly class ScheduleResolutionData
{
    public function __construct(
        public int $employeeId,
        public CarbonImmutable $date,
        public ?WorkSchedule $schedule,
    ) {}

    public function hasSchedule(): bool
    {
        return $this->schedule !== null;
    }
}
