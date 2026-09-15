<?php

namespace App\Domain\Penalty\DTOs;

use App\Enums\PenaltyViolationType;
use Carbon\CarbonImmutable;

final readonly class CreateManualPenaltyData
{
    public function __construct(
        public int $employeeId,
        public int $penaltyRuleId,
        public ?int $attendanceId,
        public PenaltyViolationType $violationType,
        public ?string $violationCustom,
        public float $points,
        public string $reason,
        public CarbonImmutable $occurredAt,
    ) {}
}
