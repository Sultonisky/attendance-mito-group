<?php

namespace App\Domain\Attendance\Rules;

use App\Enums\AttendanceStatus;

/**
 * Determines attendance record status based on domain context.
 *
 * Phase 7 implements a simplified state machine. Holiday, leave, and business
 * trip states are not calculated here because those engines are not yet
 * implemented. The engine documents the intended precedence without
 * fabricating unavailable data.
 */
class AttendanceStateRule
{
    public function determine(
        bool $hasPolicy,
        bool $hasSchedule,
        bool $hasOpenSession,
        bool $isLate,
        bool $isEarlyCheckout,
    ): AttendanceStatus {
        if (! $hasPolicy || ! $hasSchedule) {
            return AttendanceStatus::Absent;
        }

        if ($hasOpenSession) {
            return AttendanceStatus::Incomplete;
        }

        if ($isLate) {
            return AttendanceStatus::Late;
        }

        if ($isEarlyCheckout) {
            return AttendanceStatus::Late;
        }

        return AttendanceStatus::Present;
    }
}
