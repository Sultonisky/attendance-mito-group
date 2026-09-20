<?php

namespace App\Actions\User;

use App\Actions\Action;
use App\Actions\Audit\RecordAuditAction;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class DeleteUser implements Action
{
    public function __construct(
        private readonly RecordAuditAction $audit,
    ) {}

    /**
     * Hard-delete a user account.
     *
     * Guards:
     * - Cannot delete yourself.
     * - Cannot delete the last SUPER_ADMIN.
     *
     * @throws \RuntimeException
     */
    public function execute(User $user, ?User $actor, ?Request $request = null): void
    {
        // Prevent self-deletion
        if ($actor && $actor->getKey() === $user->getKey()) {
            throw new \RuntimeException('You cannot delete your own account.');
        }

        // Prevent deleting the last SUPER_ADMIN
        if ($user->hasRole('SUPER_ADMIN')) {
            $superAdminCount = User::role('SUPER_ADMIN')->count();
            if ($superAdminCount <= 1) {
                throw new \RuntimeException('Cannot delete the last SUPER_ADMIN account.');
            }
        }

        DB::transaction(function () use ($user, $actor, $request): void {
            $snapshot = ['name' => $user->name, 'email' => $user->email];

            // Revoke all roles and tokens before hard-delete
            $user->syncRoles([]);
            $user->tokens()->delete();
            $user->delete();

            $this->audit->execute(
                $actor?->getKey(),
                'user.deleted',
                $user,
                $snapshot,
                null,
                $request,
            );
        });
    }
}
