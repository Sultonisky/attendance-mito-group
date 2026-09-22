<?php

namespace App\Services\Outsource\Session;

use App\Enums\OutsourceAttendanceSessionStatus;
use Carbon\CarbonImmutable;
use Illuminate\Redis\Connections\Connection;
use Illuminate\Support\Facades\Redis;
use Throwable;

class RedisOutsourceSessionStore implements OutsourceSessionStoreInterface
{
    public function put(OutsourceSessionData $session): void
    {
        $ttl = $this->ttlSeconds($session->expiresAt);
        if ($ttl <= 0) {
            return;
        }

        $redis = $this->redis();
        $payload = json_encode($session->toArray(), JSON_THROW_ON_ERROR);

        $redis->setex($this->sessionKey($session->id), $ttl, $payload);
        $redis->setex($this->outsourceIndexKey($session->outsourceId), $ttl, $session->id);

        if ($session->deviceFingerprint !== '') {
            $deviceKey = $this->deviceIndexKey($session->deviceFingerprint);
            $redis->sadd($deviceKey, $session->id);
            $redis->expire($deviceKey, $ttl);
        }
    }

    public function find(string $sessionId): ?OutsourceSessionData
    {
        try {
            $raw = $this->redis()->get($this->sessionKey($sessionId));
        } catch (Throwable) {
            throw $this->unavailable();
        }

        if (! is_string($raw) || $raw === '') {
            return null;
        }

        try {
            /** @var array<string, mixed> $payload */
            $payload = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);

            return OutsourceSessionData::fromArray($payload);
        } catch (Throwable) {
            $this->delete($sessionId);

            return null;
        }
    }

    public function touch(string $sessionId): ?OutsourceSessionData
    {
        $session = $this->find($sessionId);
        if ($session === null) {
            return null;
        }

        $updated = $session->withLastUsedAt(CarbonImmutable::now());
        $this->put($updated);

        return $updated;
    }

    public function delete(string $sessionId): void
    {
        $session = null;
        try {
            $raw = $this->redis()->get($this->sessionKey($sessionId));
            if (is_string($raw) && $raw !== '') {
                /** @var array<string, mixed> $payload */
                $payload = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
                $session = OutsourceSessionData::fromArray($payload);
            }
        } catch (Throwable) {
            // Still attempt key deletion below.
        }

        $redis = $this->redis();
        $redis->del($this->sessionKey($sessionId));

        if ($session === null) {
            return;
        }

        $indexKey = $this->outsourceIndexKey($session->outsourceId);
        if ($redis->get($indexKey) === $sessionId) {
            $redis->del($indexKey);
        }

        if ($session->deviceFingerprint !== '') {
            $redis->srem($this->deviceIndexKey($session->deviceFingerprint), $sessionId);
        }
    }

    public function revokeByOutsource(int $outsourceId, ?string $exceptSessionId = null): void
    {
        $activeId = $this->redis()->get($this->outsourceIndexKey($outsourceId));
        if (! is_string($activeId) || $activeId === '' || $activeId === $exceptSessionId) {
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

        foreach ($this->findActiveByDevice($fingerprint) as $session) {
            if ($exceptOutsourceId !== null && $session->outsourceId === $exceptOutsourceId) {
                continue;
            }

            $this->delete($session->id);
        }
    }

    public function findActiveByDevice(string $deviceFingerprint): array
    {
        $fingerprint = trim($deviceFingerprint);
        if ($fingerprint === '') {
            return [];
        }

        try {
            $ids = $this->redis()->smembers($this->deviceIndexKey($fingerprint));
        } catch (Throwable) {
            throw $this->unavailable();
        }

        if (! is_array($ids)) {
            return [];
        }

        $result = [];
        foreach ($ids as $sessionId) {
            if (! is_string($sessionId) || $sessionId === '') {
                continue;
            }

            $session = $this->find($sessionId);
            if ($session !== null && $session->isActive()) {
                $result[] = $session;
            }
        }

        return $result;
    }

    private function redis(): Connection
    {
        return Redis::connection((string) config('outsource_session.redis.connection', 'default'));
    }

    private function sessionKey(string $sessionId): string
    {
        return (string) config('outsource_session.redis.session_prefix').$sessionId;
    }

    private function outsourceIndexKey(int $outsourceId): string
    {
        return (string) config('outsource_session.redis.outsource_index_prefix').$outsourceId;
    }

    private function deviceIndexKey(string $fingerprint): string
    {
        return (string) config('outsource_session.redis.device_index_prefix').$fingerprint;
    }

    private function ttlSeconds(CarbonImmutable $expiresAt): int
    {
        return max(0, $expiresAt->getTimestamp() - CarbonImmutable::now()->getTimestamp());
    }

    private function unavailable(): OutsourceSessionStoreUnavailableException
    {
        return new OutsourceSessionStoreUnavailableException(
            'Outsource session store is temporarily unavailable.'
        );
    }
}
