<?php

namespace App\Enums;

/**
 * Approval lifecycle shared by leave requests, permission requests, and
 * overtime requests.
 */
enum ApprovalStatus: string
{
    case Pending = 'pending';

    case Approved = 'approved';

    case Rejected = 'rejected';

    case Cancelled = 'cancelled';
}
