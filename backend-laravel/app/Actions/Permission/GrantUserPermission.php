<?php

namespace App\Actions\Permission;

use App\Actions\Action;
use App\Actions\Audit\RecordAuditAction;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Permission;

class GrantUserPermission implements Action
{
    public function __construct(
        private readonly RecordAuditAction $audit,
    ) {}

    /**
     * Grant a permission to a single user (access is per user).
     *
     * @throws ValidationException for SUPER_ADMIN, which already has everything
     */
    public function execute(Permission $permission, User $user, ?User $actor, ?Request $request = null): void
    {
        if ($user->hasRole('SUPER_ADMIN')) {
            throw ValidationException::withMessages([
                'user_id' => 'Super Admin already has every permission.',
            ]);
        }

        if ($user->hasDirectPermission($permission)) {
            return;
        }

        DB::transaction(function () use ($permission, $user, $actor, $request): void {
            $user->givePermissionTo($permission);

            $this->audit->execute(
                $actor?->getKey(),
                'user.permission_granted',
                $user,
                null,
                ['permission' => $permission->name],
                $request,
            );
        });
    }
}
