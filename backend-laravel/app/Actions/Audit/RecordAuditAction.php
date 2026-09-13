<?php

namespace App\Actions\Audit;

use App\Models\AuditLog;
use Illuminate\Http\Request;

/**
 * Record an application audit entry.
 *
 * This action is transport-agnostic. Callers pass in any request metadata
 * they already have; no HTTP coupling is introduced into the domain layer.
 */
class RecordAuditAction
{
    public function execute(
        ?int $actorId,
        string $action,
        ?object $auditable,
        ?array $oldValues,
        ?array $newValues,
        ?Request $request = null,
        ?array $metadata = null
    ): void {
        $ipAddress = null;
        $userAgent = null;

        if ($request !== null) {
            $ipAddress = $request->ip();
            $userAgent = $request->userAgent();
        }

        AuditLog::create([
            'actor_id' => $actorId,
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
}
