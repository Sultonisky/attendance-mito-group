<?php

namespace App\Enums;

enum PenaltyViolationType: string
{
    case Late = 'LATE';

    case EarlyCheckout = 'EARLY_CHECKOUT';

    case Absence = 'ABSENCE';

    case IncompleteAttendance = 'INCOMPLETE_ATTENDANCE';

    case Other = 'OTHER';
}
