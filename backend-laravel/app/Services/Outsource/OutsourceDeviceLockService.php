<?php

namespace App\Services\Outsource;

use App\Domain\Attendance\Services\OutsourceSessionExpiry;
use App\Enums\AttendanceSessionStatus;
use App\Models\AttendanceSession;
use App\Services\Outsource\Session\OutsourceSessionStoreInterface;
use Carbon\CarbonImmutable;

class OutsourceDeviceLockService
{
    public function __construct(
        protected OutsourceSessionStoreInterface $sessions,
        protected OutsourceSessionExpiry $sessionExpiry,
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

        $now = CarbonImmutable::now('UTC');

        foreach ($this->sessions->findActiveByDevice($fingerprint) as $session) {
            if ($exceptOutsourceId !== null && $session->outsourceId === $exceptOutsourceId) {
                continue;
            }

            $openAttendance = AttendanceSession::query()
                ->where('status', AttendanceSessionStatus::Open->value)
                ->whereHas('attendanceRecord', function ($query) use ($session) {
                    $query->where('outsource_id', $session->outsourceId);
                })
                ->orderByDesc('check_in_at')
                ->first();

            if ($openAttendance === null) {
                continue;
            }

            if ($this->sessionExpiry->expireIfPastLimit($openAttendance, $now)) {
                continue;
            }

            return $session->outsourceId;
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
