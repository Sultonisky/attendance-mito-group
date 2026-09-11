<?php

namespace App\Domain\Attendance\Rules;

use Carbon\CarbonImmutable;

/**
 * Detects early check-out based on the resolved shift end time.
 *
 * Does not calculate overtime or penalty points. Those belong to later phases.
 */
class EarlyCheckoutRule
{
    public function isEarlyCheckout(
        CarbonImmutable $checkOutAt,
        ?CarbonImmutable $scheduledEnd,
    ): bool {
        if ($scheduledEnd === null) {
            return false;
        }

        return $checkOutAt->lessThan($scheduledEnd);
    }
}
