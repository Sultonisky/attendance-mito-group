<?php

namespace App\Enums;

/**
 * Daily attendance status of an employee.
 *
 * These are calculated/determined by the future Attendance Engine and follow
 * the documented precedence (Holiday -> Off Day -> Leave -> Business Trip ->
 * Scheduled Work -> Absent -> Incomplete -> Present). The database stores the
 * stable string value in `attendance_records.status`.
 */
enum AttendanceStatus: string
{
    case Present = 'present';

    case Absent = 'absent';

    case Late = 'late';

    case Incomplete = 'incomplete';

    case OffDay = 'off_day';

    case Holiday = 'holiday';

    case Leave = 'leave';

    case BusinessTrip = 'business_trip';
}
