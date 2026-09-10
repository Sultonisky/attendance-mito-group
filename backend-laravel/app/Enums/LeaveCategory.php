<?php

namespace App\Enums;

/**
 * Leave type category.
 *
 * Annual leave accrues and expires under the annual-leave rules; special
 * leave (bereavement, sickness, etc.) does not deduct annual leave unless
 * explicitly configured otherwise.
 */
enum LeaveCategory: string
{
    case Annual = 'annual';

    case Special = 'special';
}
