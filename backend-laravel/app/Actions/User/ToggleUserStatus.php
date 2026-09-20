<?php

namespace App\Actions\User;

use App\Actions\Action;
use App\Actions\Audit\RecordAuditAction;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ToggleUserStatus implements Action
{
    public function __construct(
        private readonly RecordAuditAction $audit,
    ) {}

    public function execute(User $user, ?User $actor, ?Request $request = null): User
    {
        return DB::transaction(function () use ($user, $actor, $request): User {
            $oldStatus = $user->status;
            $newStatus = $oldStatus === 'active' ? 'inactive' : 'active';

            $user->update(['status' => $newStatus]);

            $this->audit->execute(
                $actor?->getKey(),
                'user.status_toggled',
                $user,
                ['status' => $oldStatus],
                ['status' => $newStatus],
                $request,
            );

            return $user->refresh();
        });
    }
}
