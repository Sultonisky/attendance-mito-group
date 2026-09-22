<?php

namespace App\Services\Outsource\Session;

use Carbon\CarbonInterface;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Cookie;

class OutsourceSessionCookie
{
    public function name(): string
    {
        return (string) config('outsource_session.cookie.name', 'outsource_session');
    }

    public function read(Request $request): ?string
    {
        $value = $request->cookies->get($this->name()) ?? $request->cookie($this->name());
        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value !== '' ? $value : null;
    }

    public function make(string $sessionId, CarbonInterface $expiresAt): Cookie
    {
        $minutes = max(1, (int) ceil(($expiresAt->getTimestamp() - now()->getTimestamp()) / 60));

        return cookie(
            $this->name(),
            $sessionId,
            $minutes,
            (string) config('outsource_session.cookie.path', '/'),
            config('outsource_session.cookie.domain'),
            $this->secure(),
            (bool) config('outsource_session.cookie.http_only', true),
            false,
            (string) config('outsource_session.cookie.same_site', 'lax'),
        );
    }

    public function forget(): Cookie
    {
        return cookie()->forget(
            $this->name(),
            (string) config('outsource_session.cookie.path', '/'),
            config('outsource_session.cookie.domain'),
        );
    }

    private function secure(): bool
    {
        $configured = config('outsource_session.cookie.secure');
        if ($configured === null || $configured === '') {
            return (bool) config('session.secure', false);
        }

        return filter_var($configured, FILTER_VALIDATE_BOOLEAN);
    }
}
