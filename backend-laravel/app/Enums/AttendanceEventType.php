<?php

namespace App\Enums;

/**
 * Raw attendance event types preserved in the event history.
 *
 * The enum is intentionally open (string backed) so future infrastructure
 * events can be added without a migration.
 */
enum AttendanceEventType: string
{
    case CheckIn = 'check_in';

    case CheckOut = 'check_out';
}
