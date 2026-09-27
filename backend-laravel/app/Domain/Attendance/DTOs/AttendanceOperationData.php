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
        public ?int $pinId = null,
    ) {}

    /**
     * Point-in-time GPS evidence for audit metadata.
     *
     * @param  array<string, mixed>  $geofence  Engine geofence result (passed, distance_meters, method).
     * @return array{latitude: float, longitude: float, accuracy_meters: float|null, geofence_passed: bool|null, distance_meters: float|null, geofence_method: string|null}
     */
    public function toAuditGps(array $geofence = []): array
    {
        $distance = $geofence['distance_meters'] ?? null;

        return [
            'latitude' => $this->latitude,
            'longitude' => $this->longitude,
            'accuracy_meters' => $this->accuracy,
            'geofence_passed' => isset($geofence['passed']) ? (bool) $geofence['passed'] : null,
            'distance_meters' => $distance !== null ? round((float) $distance, 2) : null,
            'geofence_method' => isset($geofence['method']) ? (string) $geofence['method'] : null,
        ];
    }
}
