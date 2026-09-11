<?php

namespace App\DTOs\Audit;

/**
 * Immutable data carrier for audit log records.
 *
 * This DTO keeps the audit record shape explicit at the application boundary
 * and prevents passing ad-hoc arrays through the audit foundation.
 */
final readonly class AuditRecordData
{
    public function __construct(
        public ?int $actorId,
        public string $action,
        public ?string $auditableType,
        public ?int $auditableId,
        public array $oldValues,
        public array $newValues,
        public ?string $ipAddress,
        public ?string $userAgent,
        public array $metadata,
    ) {}
}
