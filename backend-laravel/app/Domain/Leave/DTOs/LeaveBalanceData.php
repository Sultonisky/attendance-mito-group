<?php

namespace App\Domain\Leave\DTOs;

/**
 * Immutable summary of an employee's available annual balance.
 */
final readonly class LeaveBalanceData
{
    /**
     * @param  array<int, array{id: int, period: string, balance: float, expires_at: ?string}>  $batches
     */
    public function __construct(
        public int $employeeId,
        public int $leaveTypeId,
        public float $available,
        public array $batches,
    ) {}
}
