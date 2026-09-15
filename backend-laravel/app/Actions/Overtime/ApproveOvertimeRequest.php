<?php

namespace App\Actions\Overtime;

use App\Actions\Audit\RecordAuditAction;
use App\Domain\Overtime\Exceptions\InvalidOvertimeStateException;
use App\Models\OvertimeRequest;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Approve a pending overtime request atomically.
 */
class ApproveOvertimeRequest
{
    public function __construct(
        private RecordAuditAction $audit,
    ) {}

    public function execute(OvertimeRequest $request, User $actor, ?int $approvedMinutes = null, ?Request $requestObj = null): OvertimeRequest
    {
        $this->authorize($request, $actor);

        if ($request->status === 'approved') {
            return $request->fresh() ?? $request;
        }
        if ($request->status !== 'pending') {
            throw new InvalidOvertimeStateException('Only pending overtime requests can be approved.');
        }

        return DB::transaction(function () use ($request, $actor, $approvedMinutes, $requestObj): OvertimeRequest {
            $locked = OvertimeRequest::whereKey($request->id)->lockForUpdate()->firstOrFail();
            if ($locked->status === 'approved') {
                return $locked;
            }
            if ($locked->status !== 'pending') {
                throw new InvalidOvertimeStateException('Only pending overtime requests can be approved.');
            }

            $minutes = $approvedMinutes ?? $locked->requested_minutes;
            $potentialMinutes = (int) ($locked->overtimeRecord?->potential_minutes ?? 0);

            if ($minutes > $potentialMinutes) {
                throw new InvalidOvertimeStateException('Approved minutes exceed calculated potential overtime.');
            }

            $now = CarbonImmutable::now();

            $old = ['status' => $locked->status];
            $locked->update([
                'status' => 'approved',
                'approved_minutes' => $minutes,
                'approved_by' => $actor->getKey(),
                'approved_at' => $now,
            ]);

            if ($locked->overtimeRecord !== null) {
                $locked->overtimeRecord->update([
                    'approved_minutes' => $minutes,
                    'status' => 'approved',
                ]);
            }

            $this->audit->execute(
                $actor->getKey(),
                'overtime.request_approved',
                $locked,
                $old,
                ['status' => 'approved', 'approved_minutes' => $minutes],
                $requestObj,
                ['employee_id' => $locked->employee_id]
            );

            return $locked->fresh() ?? $locked;
        });
    }

    private function authorize(OvertimeRequest $request, User $actor): void
    {
        if (! $actor->can('overtime.approve')) {
            throw new InvalidOvertimeStateException('Actor is not authorized to approve overtime.');
        }
        $employee = $request->employee()->first();
        if ($employee !== null && (int) $employee->getAttribute('user_id') === (int) $actor->getKey()) {
            throw new InvalidOvertimeStateException('Self-approval of overtime is not allowed.');
        }
    }
}
