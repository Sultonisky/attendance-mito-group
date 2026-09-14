<?php

namespace App\Domain\Leave\DTOs;

use Carbon\CarbonImmutable;

/**
 * Immutable eligibility evaluation for an employee on a reference date.
 */
final readonly class LeaveEligibilityData
{
    public function __construct(
        public int $employeeId,
        public ?CarbonImmutable $joinDate,
        public ?CarbonImmutable $eligibilityDate,
        public bool $eligible,
        public bool $active,
        public string $reason,
    ) {}
}
