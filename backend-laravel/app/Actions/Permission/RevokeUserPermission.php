<?php

namespace App\Actions\Permission;

use App\Actions\Action;
use App\Actions\Audit\RecordAuditAction;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Permission;

class RevokeUserPermission implements Action
{
    public function __construct(
        private readonly RecordAuditAction $audit,
    ) {}

    /**
     * Revoke a permission from a single user (access is per user).
     *
     * @throws ValidationException for SUPER_ADMIN or when the user lacks it
     */
    public function execute(Permission $permission, User $user, ?User $actor, ?Request $request = null): void
    {
        if ($user->hasRole('SUPER_ADMIN')) {
            throw ValidationException::withMessages([
                'user' => 'Super Admin access cannot be restricted per permission.',
            ]);
        }

        if (! $user->hasDirectPermission($permission)) {
            throw ValidationException::withMessages([
                'user' => 'This user does not have this permission.',
            ]);
        }

        DB::transaction(function () use ($permission, $user, $actor, $request): void {
            $user->revokePermissionTo($permission);

            $this->audit->execute(
                $actor?->getKey(),
                'user.permission_revoked',
                $user,
                ['permission' => $permission->name],
                null,
                $request,
            );
        });
    }
}
