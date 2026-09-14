<?php

namespace App\Domain\Leave\Rules;

/**
 * Deterministic leave request lifecycle transitions.
 *
 * Only the transitions explicitly supported by Phase 9 business rules are
 * allowed. All other transitions are rejected by the domain.
 */
class LeaveStateRule
{
    /**
     * @var array<string, list<string>>
     */
    private const TRANSITIONS = [
        'pending' => ['approved', 'rejected', 'cancelled'],
        'approved' => ['cancelled'],
    ];

    public function canTransition(string $from, string $to): bool
    {
        return in_array($to, self::TRANSITIONS[$from] ?? [], true);
    }

    /**
     * @return list<string>
     */
    public function allowedFrom(string $from): array
    {
        return self::TRANSITIONS[$from] ?? [];
    }
}
