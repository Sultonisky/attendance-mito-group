<?php

namespace App\Actions\Permission;

use App\Actions\Action;
use App\Actions\Audit\RecordAuditAction;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class SyncUserPermissions implements Action
{
    public function __construct(
        private readonly RecordAuditAction $audit,
    ) {}

    /**
     * Replace a user's full permission set. Access is per user, so this is
     * exactly what the user can do afterwards (independent of their role).
     *
     * @param  list<string>  $permissions
     *
     * @throws ValidationException for SUPER_ADMIN, which bypasses all checks
     */
    public function execute(User $user, array $permissions, ?User $actor, ?Request $request = null): User
    {
        if ($user->hasRole('SUPER_ADMIN')) {
            throw ValidationException::withMessages([
                'user' => 'Super Admin has full access and cannot be restricted.',
            ]);
        }

        return DB::transaction(function () use ($user, $permissions, $actor, $request): User {
            $before = $user->getDirectPermissions()->pluck('name')->sort()->values()->all();

            $user->syncPermissions($permissions);

            $after = $user->fresh()->getDirectPermissions()->pluck('name')->sort()->values()->all();
            $added = array_values(array_diff($after, $before));
            $removed = array_values(array_diff($before, $after));

            if ($added !== [] || $removed !== []) {
                $this->audit->execute(
                    $actor?->getKey(),
                    'user.permissions_updated',
                    $user,
                    ['permissions' => $before],
                    ['permissions' => $after],
                    $request,
                    ['added' => $added, 'removed' => $removed],
                );
            }

            return $user->fresh();
        });
    }
}
