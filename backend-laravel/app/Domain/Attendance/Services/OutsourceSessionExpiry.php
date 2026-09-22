<?php

namespace App\Domain\Attendance\Services;

use App\Enums\AttendanceSessionStatus;
use App\Enums\AttendanceStatus;
use App\Models\AttendanceSession;
use Carbon\CarbonImmutable;

/**
 * Lazy expiry for outsource attendance sessions that exceed the configured
 * max open duration. Authoritative attendance remains in PostgreSQL.
 */
class OutsourceSessionExpiry
{
    public function maxHours(): int
    {
        return max(1, (int) config('attendance.outsource_max_session_hours', 20));
    }

    public function isPastLimit(AttendanceSession $session, ?CarbonImmutable $now = null): bool
    {
        if ($session->status !== AttendanceSessionStatus::Open->value) {
            return false;
        }

        $nowTs = ($now ?? CarbonImmutable::now('UTC'))->getTimestamp();
        $checkInTs = CarbonImmutable::parse($session->check_in_at)->getTimestamp();
        $limitTs = $checkInTs + ($this->maxHours() * 3600);

        return $nowTs > $limitTs;
    }

    /**
     * Mark an open outsource session as expired and lock the daily record
     * as incomplete. Returns true when a transition occurred.
     */
    public function expireIfPastLimit(AttendanceSession $session, ?CarbonImmutable $now = null): bool
    {
        if (! $this->isPastLimit($session, $now)) {
            return false;
        }

        $session->loadMissing('attendanceRecord');

        $session->update([
            'status' => AttendanceSessionStatus::Expired->value,
            'check_out_at' => null,
            'duration_minutes' => null,
        ]);

        $record = $session->attendanceRecord;
        if ($record !== null) {
            $record->update(['status' => AttendanceStatus::Incomplete->value]);
        }

        return true;
    }
}
