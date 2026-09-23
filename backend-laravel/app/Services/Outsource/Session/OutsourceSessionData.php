<?php

namespace App\Services\Outsource\Session;

use App\Enums\OutsourceAttendanceSessionStatus;
use Carbon\CarbonImmutable;

/**
 * Ephemeral public outsource session (Redis / array store — not PostgreSQL).
 *
 * storeId = assigned cabang. checkInPinId is optional metadata after first IN;
 * checkout may use a different allowed pin in the same cabang.
 */
final class OutsourceSessionData
{
    public function __construct(
        public readonly string $id,
        public readonly int $outsourceId,
        public readonly int $storeId,
        public readonly string $status,
        public readonly string $deviceFingerprint,
        public readonly ?string $ipAddress,
        public readonly ?string $userAgent,
        public readonly CarbonImmutable $createdAt,
        public readonly CarbonImmutable $expiresAt,
        public readonly ?CarbonImmutable $lastUsedAt = null,
        public readonly ?int $checkInPinId = null,
    ) {}

    public function isActive(): bool
    {
        return $this->status === OutsourceAttendanceSessionStatus::Active->value
            && $this->expiresAt->isFuture();
    }

    /**
     * @return array{
     *   id: string,
     *   outsource_id: int,
     *   store_id: int,
     *   status: string,
     *   device_fingerprint: string,
     *   ip_address: ?string,
     *   user_agent: ?string,
     *   created_at: string,
     *   expires_at: string,
     *   last_used_at: ?string,
     *   check_in_pin_id: ?int
     * }
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'outsource_id' => $this->outsourceId,
            'store_id' => $this->storeId,
            'status' => $this->status,
            'device_fingerprint' => $this->deviceFingerprint,
            'ip_address' => $this->ipAddress,
            'user_agent' => $this->userAgent,
            'created_at' => $this->createdAt->toIso8601String(),
            'expires_at' => $this->expiresAt->toIso8601String(),
            'last_used_at' => $this->lastUsedAt?->toIso8601String(),
            'check_in_pin_id' => $this->checkInPinId,
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public static function fromArray(array $payload): self
    {
        return new self(
            id: (string) $payload['id'],
            outsourceId: (int) $payload['outsource_id'],
            storeId: (int) $payload['store_id'],
            status: (string) $payload['status'],
            deviceFingerprint: (string) ($payload['device_fingerprint'] ?? ''),
            ipAddress: isset($payload['ip_address']) ? (string) $payload['ip_address'] : null,
            userAgent: isset($payload['user_agent']) ? (string) $payload['user_agent'] : null,
            createdAt: CarbonImmutable::parse((string) $payload['created_at']),
            expiresAt: CarbonImmutable::parse((string) $payload['expires_at']),
            lastUsedAt: isset($payload['last_used_at']) && $payload['last_used_at'] !== null
                ? CarbonImmutable::parse((string) $payload['last_used_at'])
                : null,
            checkInPinId: isset($payload['check_in_pin_id']) && $payload['check_in_pin_id'] !== null
                ? (int) $payload['check_in_pin_id']
                : null,
        );
    }

    public function withLastUsedAt(CarbonImmutable $lastUsedAt): self
    {
        return new self(
            id: $this->id,
            outsourceId: $this->outsourceId,
            storeId: $this->storeId,
            status: $this->status,
            deviceFingerprint: $this->deviceFingerprint,
            ipAddress: $this->ipAddress,
            userAgent: $this->userAgent,
            createdAt: $this->createdAt,
            expiresAt: $this->expiresAt,
            lastUsedAt: $lastUsedAt,
            checkInPinId: $this->checkInPinId,
        );
    }

    public function withDeviceFingerprint(string $fingerprint): self
    {
        return new self(
            id: $this->id,
            outsourceId: $this->outsourceId,
            storeId: $this->storeId,
            status: $this->status,
            deviceFingerprint: $fingerprint,
            ipAddress: $this->ipAddress,
            userAgent: $this->userAgent,
            createdAt: $this->createdAt,
            expiresAt: $this->expiresAt,
            lastUsedAt: $this->lastUsedAt,
            checkInPinId: $this->checkInPinId,
        );
    }

    public function withCheckInPinId(int $pinId): self
    {
        return new self(
            id: $this->id,
            outsourceId: $this->outsourceId,
            storeId: $this->storeId,
            status: $this->status,
            deviceFingerprint: $this->deviceFingerprint,
            ipAddress: $this->ipAddress,
            userAgent: $this->userAgent,
            createdAt: $this->createdAt,
            expiresAt: $this->expiresAt,
            lastUsedAt: $this->lastUsedAt,
            checkInPinId: $pinId,
        );
    }
}
