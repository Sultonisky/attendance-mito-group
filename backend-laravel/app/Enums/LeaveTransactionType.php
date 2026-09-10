<?php

namespace App\Enums;

/**
 * Append-only leave balance ledger transaction types.
 */
enum LeaveTransactionType: string
{
    case Accrual = 'accrual';

    case Consumption = 'consumption';

    case Adjustment = 'adjustment';

    case Expiration = 'expiration';

    case Reversal = 'reversal';
}
