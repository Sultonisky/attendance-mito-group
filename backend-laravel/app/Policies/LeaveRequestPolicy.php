<?php

namespace App\Policies;

use App\Models\LeaveRequest;
use App\Models\User;

/**
 * Leave request authorization. Server-authoritative; Vue checks are UX only.
 */
class LeaveRequestPolicy
{
    public function view(User $user, LeaveRequest $leave): bool
    {
        if ($user->can('leave.approve') || $user->can('leave.reject') || $user->can('leave.cancel')) {
            return true;
        }
        $employee = $user->employee()->first();

        return $employee !== null && (int) $leave->employee_id === (int) $employee->getKey();
    }

    public function approve(User $user, LeaveRequest $leave): bool
    {
        if (! $user->can('leave.approve')) {
            return false;
        }

        $employee = $user->employee()->first();

        if ($employee !== null && (int) $leave->employee_id === (int) $employee->getKey()) {
            return false;
        }

        return true;
    }

    public function reject(User $user, LeaveRequest $leave): bool
    {
        return $user->can('leave.reject');
    }

    public function cancel(User $user, LeaveRequest $leave): bool
    {
        if ($user->can('leave.cancel')) {
            return true;
        }
        $employee = $user->employee()->first();

        return $employee !== null && (int) $leave->employee_id === (int) $employee->getKey();
    }
}
