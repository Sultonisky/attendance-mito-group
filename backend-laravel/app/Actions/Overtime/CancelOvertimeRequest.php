<?php

namespace App\Actions\Overtime;

use App\Actions\Audit\RecordAuditAction;
use App\Domain\Overtime\Exceptions\InvalidOvertimeStateException;
use App\Models\OvertimeRequest;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Cancel an overtime request atomically.
 */
class CancelOvertimeRequest
{
    public function __construct(
        private RecordAuditAction $audit,
    ) {}

    public function execute(OvertimeRequest $request, User $actor, bool $isOwner, ?Request $requestObj = null): OvertimeRequest
    {
        if ($request->status === 'cancelled') {
            return $request->fresh() ?? $request;
        }
        if ($request->status !== 'pending') {
            throw new InvalidOvertimeStateException('Only pending overtime requests can be cancelled.');
        }

        if (! $isOwner && ! $actor->can('overtime.cancel')) {
            throw new InvalidOvertimeStateException('Actor is not authorized to cancel this overtime request.');
        }

        return DB::transaction(function () use ($request, $actor, $requestObj): OvertimeRequest {
            $locked = OvertimeRequest::whereKey($request->id)->lockForUpdate()->firstOrFail();
            if ($locked->status === 'cancelled') {
                return $locked;
            }
            if ($locked->status !== 'pending') {
                throw new InvalidOvertimeStateException('Only pending overtime requests can be cancelled.');
            }

            $old = ['status' => $locked->status];
            $locked->update(['status' => 'cancelled']);

            if ($locked->overtimeRecord !== null) {
                $locked->overtimeRecord->update([
                    'status' => 'potential',
                ]);
            }

            $this->audit->execute(
                $actor->getKey(),
                'overtime.request_cancelled',
                $locked,
                $old,
                ['status' => 'cancelled'],
                $requestObj,
                ['employee_id' => $locked->employee_id]
            );

            return $locked->fresh() ?? $locked;
        });
    }
}
