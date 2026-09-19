<?php

namespace App\Services\Outsource;

use App\Enums\AttendanceSessionStatus;
use App\Enums\OutsourceAttendanceSessionStatus;
use App\Models\AttendanceSession;
use App\Models\OutsourceAttendanceSession;

class OutsourceDeviceLockService
{
    /**
     * Returns the other outsource id that currently holds an open clock-in on this device, if any.
     */
    public function findOpenOutsourceIdForDevice(string $deviceFingerprint, ?int $exceptOutsourceId = null): ?int
    {
        $fingerprint = trim($deviceFingerprint);
        if ($fingerprint === '') {
            return null;
        }

        $activeSessions = OutsourceAttendanceSession::query()
            ->where('device_fingerprint', $fingerprint)
            ->where('status', OutsourceAttendanceSessionStatus::Active->value)
            ->when(
                $exceptOutsourceId !== null,
                fn ($query) => $query->where('outsource_id', '!=', $exceptOutsourceId)
            )
            ->get(['id', 'outsource_id']);

        foreach ($activeSessions as $session) {
            $hasOpenAttendance = AttendanceSession::query()
                ->where('status', AttendanceSessionStatus::Open->value)
                ->whereHas('attendanceRecord', function ($query) use ($session) {
                    $query->where('outsource_id', $session->outsource_id);
                })
                ->exists();

            if ($hasOpenAttendance) {
                return (int) $session->outsource_id;
            }
        }

        return null;
    }

    public function revokeActiveSessionsForOutsource(int $outsourceId, ?int $exceptSessionId = null): void
    {
        OutsourceAttendanceSession::query()
            ->where('outsource_id', $outsourceId)
            ->where('status', OutsourceAttendanceSessionStatus::Active->value)
            ->when(
                $exceptSessionId !== null,
                fn ($query) => $query->whereKeyNot($exceptSessionId)
            )
            ->update([
                'status' => OutsourceAttendanceSessionStatus::Revoked->value,
                'completed_at' => now(),
            ]);
    }

    public function revokeActiveSessionsForDevice(string $deviceFingerprint, ?int $exceptOutsourceId = null): void
    {
        $fingerprint = trim($deviceFingerprint);
        if ($fingerprint === '') {
            return;
        }

        OutsourceAttendanceSession::query()
            ->where('device_fingerprint', $fingerprint)
            ->where('status', OutsourceAttendanceSessionStatus::Active->value)
            ->when(
                $exceptOutsourceId !== null,
                fn ($query) => $query->where('outsource_id', '!=', $exceptOutsourceId)
            )
            ->update([
                'status' => OutsourceAttendanceSessionStatus::Revoked->value,
                'completed_at' => now(),
            ]);
    }
}
