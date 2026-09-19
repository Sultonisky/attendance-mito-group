<?php

namespace App\Actions\Outsource;

use App\Actions\Action;
use App\Actions\Audit\RecordAuditAction;
use App\Models\Outsource;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class DeleteOutsourcePerson implements Action
{
    public function __construct(
        private readonly RecordAuditAction $audit,
    ) {}

    /**
     * Soft-delete an outsource person.
     * Deactivates all store assignments before deleting.
     */
    public function execute(Outsource $person, ?User $actor, ?Request $request = null): void
    {
        DB::transaction(function () use ($person, $actor, $request): void {
            $snapshot = ['name' => $person->name, 'outsource_code' => $person->outsource_code];

            // Deactivate all store assignments
            $person->stores()->updateExistingPivot(
                $person->stores()->allRelatedIds(),
                ['status' => 'inactive'],
            );

            $person->delete(); // SoftDeletes

            $this->audit->execute(
                $actor?->getKey(),
                'outsource_person.deleted',
                $person,
                $snapshot,
                null,
                $request,
            );
        });
    }
}
