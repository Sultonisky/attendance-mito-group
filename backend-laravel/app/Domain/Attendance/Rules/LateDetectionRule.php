<?php

namespace App\Domain\Attendance\Rules;

use Carbon\CarbonImmutable;

/**
 * Detects late check-in based on the resolved shift start time.
 *
 * Does not invent grace-period values. If the schedule/policy does not expose
 * a grace period, the rule compares raw shift start time against actual IN time.
 */
class LateDetectionRule
{
    public function isLate(
        CarbonImmutable $checkInAt,
        ?CarbonImmutable $scheduledStart,
        ?int $graceMinutes = null,
    ): bool {
        if ($scheduledStart === null) {
            return false;
        }

        $effectiveStart = $scheduledStart;

        if ($graceMinutes !== null && $graceMinutes > 0) {
            $effectiveStart = $scheduledStart->addMinutes($graceMinutes);
        }

        return $checkInAt->greaterThan($effectiveStart);
    }
}
