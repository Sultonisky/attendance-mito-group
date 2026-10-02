<?php

namespace App\Actions\Permission;

use App\Actions\Action;
use App\Actions\Audit\RecordAuditAction;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Role;

class UpdateRolePermissions implements Action
{
    public function __construct(
        private readonly RecordAuditAction $audit,
    ) {}

    /**
     * Replace a role's permission template: copied onto users when they are
     * created with or switched to this role. Existing users are untouched —
     * access is per user.
     *
     * @param  list<string>  $permissions
     *
     * @throws ValidationException for SUPER_ADMIN, which bypasses all checks
     */
    public function execute(Role $role, array $permissions, ?User $actor, ?Request $request = null): Role
    {
        if ($role->name === 'SUPER_ADMIN') {
            throw ValidationException::withMessages([
                'role' => 'Super Admin has full access and cannot be restricted.',
            ]);
        }

        return DB::transaction(function () use ($role, $permissions, $actor, $request): Role {
            $before = $role->permissions()->pluck('name')->sort()->values()->all();

            $role->syncPermissions($permissions);

            $after = $role->permissions()->pluck('name')->sort()->values()->all();
            $added = array_values(array_diff($after, $before));
            $removed = array_values(array_diff($before, $after));

            if ($added !== [] || $removed !== []) {
                $this->audit->execute(
                    $actor?->getKey(),
                    'role.permissions_updated',
                    $role,
                    ['permissions' => $before],
                    ['permissions' => $after],
                    $request,
                    ['added' => $added, 'removed' => $removed],
                );
            }

            return $role->load('permissions');
        });
    }
}
