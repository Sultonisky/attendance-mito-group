<?php

namespace App\Enums;

/**
 * Overtime processing state.
 *
 * Keeps the documented concepts distinct:
 * potential (detected), requested, approved, actual (recorded).
 */
enum OvertimeStatus: string
{
    case Potential = 'potential';

    case Requested = 'requested';

    case Approved = 'approved';

    case Actual = 'actual';
}
