<?php

namespace App\Enums;

/**
 * Generic active/inactive record state (work locations, leave types,
 * face profiles, penalty rules, schedules).
 */
enum RecordStatus: string
{
    case Active = 'active';

    case Inactive = 'inactive';
}
