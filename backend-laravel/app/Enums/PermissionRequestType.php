<?php

namespace App\Enums;

/**
 * Permission (izin) request kinds.
 */
enum PermissionRequestType: string
{
    case LateArrival = 'late_arrival';

    case EarlyCheckout = 'early_checkout';

    case Personal = 'personal';

    case Business = 'business';
}
