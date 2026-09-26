<?php

namespace App\Http\Resources\Report;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AuditLogResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'action' => $this->action,
            'actor' => $this->resolveActor(),
            'auditable_type' => $this->auditable_type,
            'auditable_id' => $this->auditable_id,
            'old_values' => $this->old_values,
            'new_values' => $this->new_values,
            'ip_address' => $this->ip_address,
            'user_agent' => $this->user_agent,
            'metadata' => $this->metadata,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }

    /**
     * Prefer the linked User actor. For public outsource attendance, actor_id
     * is null and identity lives in metadata (actor_kind=outsource).
     *
     * @return array{id: int|null, name: string, email: string|null, kind: string}|null
     */
    private function resolveActor(): ?array
    {
        if ($this->actor !== null) {
            return [
                'id' => $this->actor->id,
                'name' => $this->actor->name,
                'email' => $this->actor->email,
                'kind' => 'user',
            ];
        }

        $metadata = is_array($this->metadata) ? $this->metadata : [];
        if (($metadata['actor_kind'] ?? null) !== 'outsource') {
            return null;
        }

        $name = trim((string) ($metadata['outsource_name'] ?? ''));
        if ($name === '') {
            $name = 'Outsource';
        }

        $code = $metadata['outsource_code'] ?? null;

        return [
            'id' => isset($metadata['outsource_id']) ? (int) $metadata['outsource_id'] : null,
            'name' => $name,
            'email' => is_string($code) && $code !== '' ? $code : null,
            'kind' => 'outsource',
        ];
    }
}
