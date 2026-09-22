<?php

namespace App\Services\Outsource\Session;

use App\Enums\OutsourceAttendanceSessionStatus;
use Carbon\CarbonImmutable;

/**
 * In-memory store for PHPUnit. Mirrors Redis session + index semantics.
 */
class ArrayOutsourceSessionStore implements OutsourceSessionStoreInterface
{
    /** @var array<string, OutsourceSessionData> */
    private array $sessions = [];

    /** @var array<int, string> outsource_id => session_id */
    private array $outsourceIndex = [];

    /** @var array<string, array<string, true>> fingerprint => [session_id => true] */
    private array $deviceIndex = [];

    public function put(OutsourceSessionData $session): void
    {
        $this->sessions[$session->id] = $session;
        $this->outsourceIndex[$session->outsourceId] = $session->id;

        if ($session->deviceFingerprint !== '') {
            $this->deviceIndex[$session->deviceFingerprint][$session->id] = true;
        }
    }

    public function find(string $sessionId): ?OutsourceSessionData
    {
        return $this->sessions[$sessionId] ?? null;
    }

    public function touch(string $sessionId): ?OutsourceSessionData
    {
        $session = $this->find($sessionId);
        if ($session === null) {
            return null;
        }

        $updated = $session->withLastUsedAt(CarbonImmutable::now());
        $this->sessions[$sessionId] = $updated;

        return $updated;
    }

    public function delete(string $sessionId): void
    {
        $session = $this->sessions[$sessionId] ?? null;
        unset($this->sessions[$sessionId]);

        if ($session === null) {
            return;
        }

        if (($this->outsourceIndex[$session->outsourceId] ?? null) === $sessionId) {
            unset($this->outsourceIndex[$session->outsourceId]);
        }

        if ($session->deviceFingerprint !== '') {
            unset($this->deviceIndex[$session->deviceFingerprint][$sessionId]);
            if (($this->deviceIndex[$session->deviceFingerprint] ?? []) === []) {
                unset($this->deviceIndex[$session->deviceFingerprint]);
            }
        }
    }

    public function revokeByOutsource(int $outsourceId, ?string $exceptSessionId = null): void
    {
        $activeId = $this->outsourceIndex[$outsourceId] ?? null;
        if ($activeId === null || $activeId === $exceptSessionId) {
            return;
        }

        $this->delete($activeId);
    }

    public function revokeByDevice(string $deviceFingerprint, ?int $exceptOutsourceId = null): void
    {
        $fingerprint = trim($deviceFingerprint);
        if ($fingerprint === '') {
            return;
        }

        foreach (array_keys($this->deviceIndex[$fingerprint] ?? []) as $sessionId) {
            $session = $this->sessions[$sessionId] ?? null;
            if ($session === null) {
                unset($this->deviceIndex[$fingerprint][$sessionId]);
                continue;
            }

            if ($session->status !== OutsourceAttendanceSessionStatus::Active->value) {
                $this->delete($sessionId);
                continue;
            }

            if ($exceptOutsourceId !== null && $session->outsourceId === $exceptOutsourceId) {
                continue;
            }

            $this->delete($sessionId);
        }
    }

    public function findActiveByDevice(string $deviceFingerprint): array
    {
        $fingerprint = trim($deviceFingerprint);
        if ($fingerprint === '') {
            return [];
        }

        $result = [];
        foreach (array_keys($this->deviceIndex[$fingerprint] ?? []) as $sessionId) {
            $session = $this->sessions[$sessionId] ?? null;
            if ($session === null) {
                unset($this->deviceIndex[$fingerprint][$sessionId]);
                continue;
            }

            if ($session->expiresAt->isPast()) {
                $this->delete($sessionId);
                continue;
            }

            if ($session->status === OutsourceAttendanceSessionStatus::Active->value) {
                $result[] = $session;
            }
        }

        return $result;
    }

    public function flush(): void
    {
        $this->sessions = [];
        $this->outsourceIndex = [];
        $this->deviceIndex = [];
    }
}
