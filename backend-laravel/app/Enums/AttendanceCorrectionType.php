<?php

namespace App\Enums;

enum AttendanceCorrectionType: string
{
    case ClockIn = 'clock_in';
    case ClockOut = 'clock_out';
    case Both = 'both';
}
