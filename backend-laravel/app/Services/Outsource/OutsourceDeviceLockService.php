<?php

namespace App\Services\Outsource;

use App\Enums\AttendanceSessionStatus;
use App\Models\AttendanceSession;
use App\Services\Outsource\Session\OutsourceSessionStoreInterface;

class OutsourceDeviceLockService
{
    public function __construct(
        protected OutsourceSessionStoreInterface $sessions,
    ) {}

    /**
     * Returns the other outsource id that currently holds an open clock-in on this device, if any.
     */
    public function findOpenOutsourceIdForDevice(string $deviceFingerprint, ?int $exceptOutsourceId = null): ?int
    {
        $fingerprint = trim($deviceFingerprint);
        if ($fingerprint === '') {
            return null;
        }

        foreach ($this->sessions->findActiveByDevice($fingerprint) as $session) {
            if ($exceptOutsourceId !== null && $session->outsourceId === $exceptOutsourceId) {
                continue;
            }

            $hasOpenAttendance = AttendanceSession::query()
                ->where('status', AttendanceSessionStatus::Open->value)
                ->whereHas('attendanceRecord', function ($query) use ($session) {
                    $query->where('outsource_id', $session->outsourceId);
                })
                ->exists();

            if ($hasOpenAttendance) {
                return $session->outsourceId;
            }
        }

        return null;
    }

    public function revokeActiveSessionsForOutsource(int $outsourceId, ?string $exceptSessionId = null): void
    {
        $this->sessions->revokeByOutsource($outsourceId, $exceptSessionId);
    }

    public function revokeActiveSessionsForDevice(string $deviceFingerprint, ?int $exceptOutsourceId = null): void
    {
        $this->sessions->revokeByDevice($deviceFingerprint, $exceptOutsourceId);
    }
}
