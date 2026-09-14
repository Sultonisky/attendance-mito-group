<?php

namespace App\Domain\Leave\DTOs;

use Carbon\CarbonImmutable;

/**
 * Immutable daily attendance leave resolution input.
 */
final readonly class LeaveResolutionData
{
    public function __construct(
        public int $employeeId,
        public CarbonImmutable $date,
        public bool $onApprovedLeave,
        public ?int $leaveRequestId = null,
    ) {}
}
