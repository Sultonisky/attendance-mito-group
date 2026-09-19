<?php

namespace App\Actions\Outsource;

use App\Actions\Action;
use App\Actions\Audit\RecordAuditAction;
use App\Models\Outsource;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ToggleOutsourcePersonStatus implements Action
{
    public function __construct(
        private readonly RecordAuditAction $audit,
    ) {}

    public function execute(Outsource $person, ?User $actor, ?Request $request = null): Outsource
    {
        return DB::transaction(function () use ($person, $actor, $request): Outsource {
            $oldStatus = $person->status;
            $newStatus = $oldStatus === 'active' ? 'inactive' : 'active';

            $person->update(['status' => $newStatus]);

            $this->audit->execute(
                $actor?->getKey(),
                'outsource_person.status_toggled',
                $person,
                ['status' => $oldStatus],
                ['status' => $newStatus],
                $request,
            );

            return $person->refresh();
        });
    }
}
