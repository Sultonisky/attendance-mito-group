<?php

namespace App\Actions\User;

use App\Actions\Action;
use App\Actions\Audit\RecordAuditAction;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class CreateUser implements Action
{
    public function __construct(
        private readonly RecordAuditAction $audit,
    ) {}

    /**
     * @param array{name: string, email: string, password: string, role: string} $input
     */
    public function execute(array $input, ?User $actor, ?Request $request = null): User
    {
        return DB::transaction(function () use ($input, $actor, $request): User {
            $user = User::create([
                'name'     => $input['name'],
                'email'    => $input['email'],
                'password' => $input['password'], // cast 'hashed' handles bcrypt
                'status'   => 'active',
            ]);

            $user->syncRoles([$input['role']]);

            $this->audit->execute(
                $actor?->getKey(),
                'user.created',
                $user,
                null,
                ['name' => $user->name, 'email' => $user->email, 'role' => $input['role']],
                $request,
            );

            return $user->load('roles');
        });
    }
}
