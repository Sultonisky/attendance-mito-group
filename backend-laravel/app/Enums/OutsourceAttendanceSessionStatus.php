<?php

namespace App\Enums;

enum OutsourceAttendanceSessionStatus: string
{
    case Active = 'active';
    case Completed = 'completed';
    case Expired = 'expired';
    case Revoked = 'revoked';
}
