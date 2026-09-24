<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

/**
 * Database-only system alert for SUPER_ADMIN inbox.
 *
 * Never queues biometric payloads, credentials, or tokens.
 */
class AdminSystemAlert extends Notification
{
    use Queueable;

    /**
     * @param  array{alert_key: string, sender: string, icon: string, body: string, href?: string|null}  $payload
     */
    public function __construct(
        private readonly array $payload,
    ) {}

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    /**
     * @return array{alert_key: string, sender: string, icon: string, body: string, href: string|null}
     */
    public function toArray(object $notifiable): array
    {
        return [
            'alert_key' => (string) $this->payload['alert_key'],
            'sender' => (string) $this->payload['sender'],
            'icon' => (string) $this->payload['icon'],
            'body' => (string) $this->payload['body'],
            'href' => isset($this->payload['href']) ? (string) $this->payload['href'] : null,
        ];
    }
}
