<?php

namespace App\Domain\Policy\DTOs;

use App\Models\Policy;
use Carbon\CarbonImmutable;

/**
 * Immutable result of policy resolution for an employee on a specific date.
 */
final readonly class PolicyResolutionData
{
    public function __construct(
        public int $employeeId,
        public CarbonImmutable $date,
        public ?Policy $policy,
    ) {}

    public function hasPolicy(): bool
    {
        return $this->policy !== null;
    }
}
