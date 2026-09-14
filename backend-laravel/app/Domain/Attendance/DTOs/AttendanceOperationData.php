<?php

namespace App\Domain\Attendance\DTOs;

use App\Enums\AttendanceEventType;
use Carbon\CarbonImmutable;

/**
 * Immutable data for a check-in or check-out operation.
 */
final readonly class AttendanceOperationData
{
    public function __construct(
        public int $employeeId,
        public float $latitude,
        public float $longitude,
        public ?float $accuracy,
        public ?string $deviceIdentifier,
        public ?string $source,
        public ?int $workLocationId,
        public CarbonImmutable $occurredAt,
        public AttendanceEventType $eventType,
    ) {}
}
