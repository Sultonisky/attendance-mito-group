<?php

namespace App\Enums;

/**
 * Policy lifecycle. Policies are configuration carriers; evaluation belongs
 * to the future Policy Engine.
 */
enum PolicyStatus: string
{
    case Draft = 'draft';

    case Active = 'active';

    case Expired = 'expired';
}
