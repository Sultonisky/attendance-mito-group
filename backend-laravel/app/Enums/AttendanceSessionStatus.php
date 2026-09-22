<?php

namespace App\Enums;

/**
 * Lifecycle state of an attendance session (IN -> OUT interval).
 */
enum AttendanceSessionStatus: string
{
    case Open = 'open';

    case Closed = 'closed';

    /** Outsource-only: open session exceeded max duration without check-out. */
    case Expired = 'expired';
}
