<?php

namespace App\Http\Resources\Notification;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin \Illuminate\Notifications\DatabaseNotification
 */
class AdminNotificationResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $data = is_array($this->data) ? $this->data : [];

        return [
            'id' => (string) $this->id,
            'sender' => [
                'name' => (string) ($data['sender'] ?? 'System'),
                'avatar' => [
                    'icon' => (string) ($data['icon'] ?? 'i-lucide-bell'),
                ],
            ],
            'body' => (string) ($data['body'] ?? ''),
            'href' => isset($data['href']) && is_string($data['href']) ? $data['href'] : null,
            'date' => $this->created_at?->toIso8601String(),
            'unread' => $this->read_at === null,
        ];
    }
}
