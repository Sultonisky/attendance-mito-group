<?php

namespace App\Actions\Outsource;

use App\Actions\Action;
use App\Actions\Audit\RecordAuditAction;
use App\Models\User;
use App\Models\WorkLocationPin;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class DeleteWorkLocationPin implements Action
{
    public function __construct(
        private readonly RecordAuditAction $audit,
    ) {}

    public function execute(WorkLocationPin $pin, ?User $actor, ?Request $request = null): void
    {
        DB::transaction(function () use ($pin, $actor, $request): void {
            $old = $pin->only(['id', 'name', 'work_location_id', 'status']);
            $pin->delete();

            $this->audit->execute(
                $actor?->getKey(),
                'work_location_pin.deleted',
                $pin,
                $old,
                null,
                $request,
            );
        });
    }
}
