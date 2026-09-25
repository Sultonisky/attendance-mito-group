<?php

namespace App\Actions\Audit;

use App\DTOs\Audit\AuditRecordData;
use App\Models\AuditLog;
use App\Models\Outsource;
use App\Models\User;
use Illuminate\Http\Request;

/**
 * Record an application audit entry.
 *
 * Supports two calling conventions:
 *
 *   1. DTO-based (preferred):
 *      $action->execute(AuditRecordData $data): AuditLog
 *
 *   2. Positional (legacy, used by CheckInEmployee / CheckOutEmployee):
 *      $action->execute(?int $actorId, string $action, ?object $auditable,
 *                        ?array $oldValues, ?array $newValues,
 *                        ?Request $request = null, ?array $metadata = null): AuditLog
 *
 * Both conventions persist via AuditLog::create() and return the created model.
 * The action is transport-agnostic; callers supply any request metadata they
 * already hold — no HTTP coupling is introduced into the domain layer.
 */
class RecordAuditAction
{
    public function execute(
        AuditRecordData|int|null $actorIdOrData,
        ?string $action = null,
        ?object $auditable = null,
        ?array $oldValues = null,
        ?array $newValues = null,
        ?Request $request = null,
        ?array $metadata = null
    ): AuditLog {
        if ($actorIdOrData instanceof AuditRecordData) {
            $data = $actorIdOrData;

            return AuditLog::create([
                'actor_id' => $data->actorId,
                'action' => $data->action,
                'auditable_type' => $data->auditableType,
                'auditable_id' => $data->auditableId,
                'old_values' => $data->oldValues,
                'new_values' => $data->newValues,
                'ip_address' => $data->ipAddress,
                'user_agent' => $data->userAgent,
                'metadata' => $data->metadata,
            ]);
        }

        // Positional-args convention (legacy callers).
        $ipAddress = $request?->ip();
        $userAgent = $request?->userAgent();

        return AuditLog::create([
            'actor_id' => $actorIdOrData,
            'action' => $action,
            'auditable_type' => $auditable !== null ? $auditable::class : null,
            'auditable_id' => $auditable !== null ? $auditable->getKey() : null,
            'old_values' => $oldValues,
            'new_values' => $newValues,
            'ip_address' => $ipAddress,
            'user_agent' => $userAgent,
            'metadata' => $metadata,
        ]);
    }

    /**
     * Convenience factory that builds an AuditRecordData from a User context.
     */
    public static function forUser(
        User $user,
        string $action,
        ?object $subject = null,
        array $oldValues = [],
        array $newValues = []
    ): AuditRecordData {
        return new AuditRecordData(
            actorId: $user->getKey(),
            action: $action,
            auditableType: $subject !== null ? $subject::class : null,
            auditableId: $subject?->getKey(),
            oldValues: $oldValues,
            newValues: $newValues,
            ipAddress: request()?->ip(),
            userAgent: request()?->userAgent(),
            metadata: [],
        );
    }

    /**
     * Audit context for public outsource attendance actors.
     *
     * Outsource personnel are not users, so actor_id stays null. Identity is
     * carried in metadata for the audit API/UI to display instead of "System".
     *
     * @param  array<string, mixed>  $oldValues
     * @param  array<string, mixed>  $newValues
     * @param  array<string, mixed>  $metadata
     */
    public static function forOutsource(
        Outsource $outsource,
        string $action,
        ?object $subject = null,
        array $oldValues = [],
        array $newValues = [],
        ?string $ipAddress = null,
        ?string $userAgent = null,
        array $metadata = [],
    ): AuditRecordData {
        return new AuditRecordData(
            actorId: null,
            action: $action,
            auditableType: $subject !== null ? $subject::class : null,
            auditableId: $subject?->getKey(),
            oldValues: $oldValues,
            newValues: $newValues,
            ipAddress: $ipAddress ?? request()?->ip(),
            userAgent: $userAgent ?? request()?->userAgent(),
            metadata: array_merge([
                'actor_kind' => 'outsource',
                'outsource_id' => $outsource->getKey(),
                'outsource_name' => $outsource->name,
                'outsource_code' => $outsource->outsource_code,
            ], $metadata),
        );
    }
}
