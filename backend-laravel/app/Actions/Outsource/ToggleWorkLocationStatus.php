<?php

namespace App\Actions\Outsource;

use App\Actions\Action;
use App\Actions\Audit\RecordAuditAction;
use App\Models\WorkLocation;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ToggleWorkLocationStatus implements Action
{
    public function __construct(
        private readonly RecordAuditAction $audit,
    ) {}

    public function execute(WorkLocation $location, ?User $actor, ?Request $request = null): WorkLocation
    {
        return DB::transaction(function () use ($location, $actor, $request): WorkLocation {
            $oldStatus = $location->status;
            $newStatus = $oldStatus === 'active' ? 'inactive' : 'active';

            $location->update(['status' => $newStatus]);

            $this->audit->execute(
                $actor?->getKey(),
                'work_location.status_toggled',
                $location,
                ['status' => $oldStatus],
                ['status' => $newStatus],
                $request,
            );

            return $location->refresh();
        });
    }
}
