<?php

namespace App\Policies;

use App\Models\AttendanceRecord;
use App\Models\User;

/**
 * Authorization policy for attendance records.
 *
 * Server-authoritative; Vue checks are UX only.
 */
class AttendanceRecordPolicy
{
    /**
     * Determine whether the user can view any attendance records.
     */
    public function viewAny(User $user): bool
    {
        return true;
    }

    /**
     * Determine whether the user can view the attendance record.
     */
    public function view(User $user, AttendanceRecord $record): bool
    {
        if ($user->can('attendance.view')) {
            return true;
        }

        $employee = $user->employee()->first();

        return $employee !== null && (int) $record->employee_id === (int) $employee->getKey();
    }
}
