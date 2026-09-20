<?php

namespace App\Actions\User;

use App\Actions\Action;
use App\Actions\Audit\RecordAuditAction;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class UpdateUser implements Action
{
    public function __construct(
        private readonly RecordAuditAction $audit,
    ) {}

    /**
     * @param array{name?: string, email?: string, password?: string|null, role?: string, status?: string} $input
     */
    public function execute(User $user, array $input, ?User $actor, ?Request $request = null): User
    {
        return DB::transaction(function () use ($user, $input, $actor, $request): User {
            $old = [
                'name'   => $user->name,
                'email'  => $user->email,
                'status' => $user->status,
                'role'   => $user->roles->first()?->name,
            ];

            $fillable = array_filter([
                'name'   => $input['name']   ?? null,
                'email'  => $input['email']  ?? null,
                'status' => $input['status'] ?? null,
            ], fn ($v) => $v !== null);

            // Password is optional on update — only set if explicitly provided
            if (!empty($input['password'])) {
                $fillable['password'] = $input['password'];
            }

            if (!empty($fillable)) {
                $user->update($fillable);
            }

            if (!empty($input['role'])) {
                $user->syncRoles([$input['role']]);
            }

            $user->refresh();

            $this->audit->execute(
                $actor?->getKey(),
                'user.updated',
                $user,
                $old,
                array_diff_key($input, ['password' => '']), // never log password
                $request,
            );

            return $user->load('roles');
        });
    }
}
