<?php

namespace App\Enums;

/**
 * Monthly recap lifecycle: DRAFT -> REVIEW -> FINALIZED -> EXPORTED.
 *
 * FINALIZED recaps are business-locked; corrections require an explicit
 * reopening/correction process.
 */
enum MonthlyRecapStatus: string
{
    case Draft = 'draft';

    case Review = 'review';

    case Finalized = 'finalized';

    case Exported = 'exported';
}
