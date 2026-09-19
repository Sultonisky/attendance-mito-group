<?php

namespace App\Actions\Outsource;

use App\Actions\Action;
use App\Actions\Audit\RecordAuditAction;
use App\Models\WorkLocation;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class DeleteWorkLocation implements Action
{
    public function __construct(
        private readonly RecordAuditAction $audit,
    ) {}

    /**
     * Soft-delete a work location.
     * Deactivates all outsource store assignments before deleting.
     */
    public function execute(WorkLocation $location, ?User $actor, ?Request $request = null): void
    {
        DB::transaction(function () use ($location, $actor, $request): void {
            $snapshot = ['code' => $location->code, 'name' => $location->name];

            // Deactivate all outsource assignments for this store
            DB::table('outsource_store_assignments')
                ->where('store_id', $location->id)
                ->whereNull('deleted_at')
                ->update(['status' => 'inactive', 'deleted_at' => now()]);

            $location->delete(); // SoftDeletes

            $this->audit->execute(
                $actor?->getKey(),
                'work_location.deleted',
                $location,
                $snapshot,
                null,
                $request,
            );
        });
    }
}
