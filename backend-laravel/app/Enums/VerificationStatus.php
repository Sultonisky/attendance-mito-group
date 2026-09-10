<?php

namespace App\Enums;

/**
 * Result of an attendance verification fact
 * (face verification, geofence, GPS accuracy).
 */
enum VerificationStatus: string
{
    case Passed = 'passed';

    case Failed = 'failed';

    case Skipped = 'skipped';
}
