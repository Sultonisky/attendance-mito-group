<?php

namespace App\Services\Outsource\Session;

interface OutsourceSessionStoreInterface
{
    public function put(OutsourceSessionData $session): void;

    public function find(string $sessionId): ?OutsourceSessionData;

    public function touch(string $sessionId): ?OutsourceSessionData;

    public function delete(string $sessionId): void;

    /**
     * Remove every active session for this outsource (optionally keep one).
     */
    public function revokeByOutsource(int $outsourceId, ?string $exceptSessionId = null): void;

    /**
     * Remove active sessions on this device (optionally keep one outsource).
     */
    public function revokeByDevice(string $deviceFingerprint, ?int $exceptOutsourceId = null): void;

    /**
     * @return list<OutsourceSessionData>
     */
    public function findActiveByDevice(string $deviceFingerprint): array;
}
