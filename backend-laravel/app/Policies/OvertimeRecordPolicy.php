<?php

namespace App\Policies;

use App\Models\OvertimeRecord;
use App\Models\User;

/**
 * Authorization policy for overtime records.
 */
class OvertimeRecordPolicy
{
    /**
     * Determine whether the user can view any overtime records.
     */
    public function viewAny(User $user): bool
    {
        return true;
    }

    /**
     * Determine whether the user can view the overtime record.
     */
    public function view(User $user, OvertimeRecord $overtime): bool
    {
        $employee = $overtime->employee;

        return $user->can('overtime.view')
            || ($employee !== null && (int) $employee->user_id === $user->getKey());
    }

    /**
     * Determine whether the user can create overtime requests.
     */
    public function create(User $user): bool
    {
        return $user->can('overtime.create');
    }
}
