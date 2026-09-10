<?php

namespace App\Enums;

/**
 * Penalty record state. Adjustments preserve original vs final points.
 */
enum PenaltyStatus: string
{
    case Applied = 'applied';

    case Adjusted = 'adjusted';

    case Voided = 'voided';
}
