<?php

namespace App\Actions\Overtime;

use App\Actions\Audit\RecordAuditAction;
use App\Domain\Overtime\Exceptions\InvalidOvertimeStateException;
use App\Models\OvertimeRequest;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Reject a pending overtime request atomically.
 */
class RejectOvertimeRequest
{
    public function __construct(
        private RecordAuditAction $audit,
    ) {}

    public function execute(OvertimeRequest $request, User $actor, ?string $reason = null, ?Request $requestObj = null): OvertimeRequest
    {
        $this->authorize($request, $actor);

        if ($request->status === 'rejected') {
            return $request->fresh() ?? $request;
        }
        if ($request->status !== 'pending') {
            throw new InvalidOvertimeStateException('Only pending overtime requests can be rejected.');
        }

        return DB::transaction(function () use ($request, $actor, $reason, $requestObj): OvertimeRequest {
            $locked = OvertimeRequest::whereKey($request->id)->lockForUpdate()->firstOrFail();
            if ($locked->status === 'rejected') {
                return $locked;
            }
            if ($locked->status !== 'pending') {
                throw new InvalidOvertimeStateException('Only pending overtime requests can be rejected.');
            }

            $old = ['status' => $locked->status];
            $locked->update([
                'status' => 'rejected',
                'reason' => $reason ?? $locked->reason,
            ]);

            if ($locked->overtimeRecord !== null) {
                $locked->overtimeRecord->update([
                    'status' => 'potential',
                ]);
            }

            $this->audit->execute(
                $actor->getKey(),
                'overtime.request_rejected',
                $locked,
                $old,
                ['status' => 'rejected', 'reason' => $reason],
                $requestObj,
                ['employee_id' => $locked->employee_id]
            );

            return $locked->fresh() ?? $locked;
        });
    }

    private function authorize(OvertimeRequest $request, User $actor): void
    {
        if (! $actor->can('overtime.reject')) {
            throw new InvalidOvertimeStateException('Actor is not authorized to reject overtime.');
        }
        $employee = $request->employee()->first();
        if ($employee !== null && (int) $employee->getAttribute('user_id') === (int) $actor->getKey()) {
            throw new InvalidOvertimeStateException('Self-rejection of overtime is not allowed.');
        }
    }
}
