<?php

namespace App\Actions\Leave;

use App\Actions\Audit\RecordAuditAction;
use App\Domain\Leave\Engines\LeaveEngine;
use App\Domain\Leave\Exceptions\InvalidLeaveStateException;
use App\Domain\Leave\Exceptions\LeaveApprovalNotAuthorizedException;
use App\Domain\Leave\Rules\LeaveStateRule;
use App\Models\LeaveRequest;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Cancel a pending or approved request.
 *
 * Pending cancellation only flips state. Approved cancellation appends
 * compensating reversal transactions; historical rows are never deleted.
 * State-safe: cancelling an already-cancelled request is a no-op.
 */
class CancelLeaveRequest
{
    public function __construct(
        private LeaveEngine $engine,
        private LeaveStateRule $states,
        private RecordAuditAction $audit,
    ) {}

    public function execute(LeaveRequest $leave, User $actor, ?Request $request = null, bool $isOwner = false): LeaveRequest
    {
        if ($leave->status === 'cancelled') {
            return $leave->fresh() ?? $leave;
        }
        if (! in_array($leave->status, ['pending', 'approved'], true)) {
            throw new InvalidLeaveStateException('Only pending or approved leave requests can be cancelled.');
        }
        if (! $isOwner && ! $actor->can('leave.cancel')) {
            throw new LeaveApprovalNotAuthorizedException('Actor is not authorized to cancel leave.');
        }

        return DB::transaction(function () use ($leave, $actor, $request): LeaveRequest {
            $locked = LeaveRequest::whereKey($leave->id)->lockForUpdate()->firstOrFail();
            if ($locked->status === 'cancelled') {
                return $locked;
            }
            if (! in_array($locked->status, ['pending', 'approved'], true)) {
                throw new InvalidLeaveStateException('Only pending or approved leave requests can be cancelled.');
            }
            $wasApproved = $locked->status === 'approved';
            $old = ['status' => $locked->status];
            $locked->update(['status' => 'cancelled']);

            if ($wasApproved) {
                $type = $locked->leaveType()->firstOrFail();
                if ($this->engine->consumesAnnualBalance($type)) {
                    $this->engine->reverseConsumption($locked, CarbonImmutable::now(), $actor->getKey());
                }
            }

            $this->audit->execute($actor->getKey(), 'leave.request_cancelled', $locked, $old, ['status' => 'cancelled'], $request, ['employee_id' => $locked->employee_id]);

            return $locked->fresh() ?? $locked;
        });
    }
}
