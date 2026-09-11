<?php

namespace App\Actions\Audit;

use App\DTOs\Audit\AuditRecordData;
use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Records an audit log entry using the existing audit_logs schema.
 *
 * This action owns the transaction boundary when combined with other
 * domain operations. It does not swallow exceptions; callers are
 * responsible for deciding whether an audit failure should abort the
 * parent transaction.
 */
class RecordAuditAction
{
    /**
     * Persist an audit record.
     */
    public function execute(AuditRecordData $data): AuditLog
    {
        return DB::transaction(function () use ($data): AuditLog {
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
        });
    }

    /**
     * Convenience factory for the common case where the actor is the
     * currently authenticated user and no metadata is needed.
     */
    public static function forUser(User $user, string $action, ?object $subject = null, array $oldValues = [], array $newValues = []): AuditRecordData
    {
        return new AuditRecordData(
            actorId: $user->getKey(),
            action: $action,
            auditableType: $subject ? $subject::class : null,
            auditableId: $subject?->getKey(),
            oldValues: $oldValues,
            newValues: $newValues,
            ipAddress: request()?->ip(),
            userAgent: request()?->userAgent(),
            metadata: [],
        );
    }
}
