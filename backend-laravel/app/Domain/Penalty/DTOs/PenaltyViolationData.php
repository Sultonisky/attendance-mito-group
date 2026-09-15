<?php

namespace App\Domain\Penalty\DTOs;

use App\Enums\PenaltyViolationType;
use App\Models\PenaltyRule;
use Carbon\CarbonImmutable;

final readonly class PenaltyViolationData
{
    public function __construct(
        public int $employeeId,
        public CarbonImmutable $date,
        public PenaltyViolationType $violationType,
        public ?int $violationValue,
        public ?PenaltyRule $rule,
        public float $points,
        public ?int $attendanceId,
    ) {}
}
