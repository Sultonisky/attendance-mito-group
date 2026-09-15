<?php

namespace App\Policies;

use App\Models\MonthlyRecap;
use App\Models\User;

class MonthlyRecapPolicy
{
    public function view(User $user, MonthlyRecap $recap): bool
    {
        $privileged = $user->can('monthly_recap.generate')
            || $user->can('monthly_recap.review')
            || $user->can('monthly_recap.finalize')
            || $user->can('monthly_recap.export');

        if ($privileged) {
            return true;
        }

        $employee = $recap->employee;

        return $employee !== null && (int) $employee->user_id === (int) $user->getKey();
    }

    public function generate(User $user): bool
    {
        return $user->can('monthly_recap.generate');
    }

    public function review(User $user): bool
    {
        return $user->can('monthly_recap.review');
    }

    public function finalize(User $user): bool
    {
        return $user->can('monthly_recap.finalize');
    }

    public function export(User $user): bool
    {
        return $user->can('monthly_recap.export');
    }

    public function reopen(User $user): bool
    {
        return $user->can('monthly_recap.finalize');
    }
}
