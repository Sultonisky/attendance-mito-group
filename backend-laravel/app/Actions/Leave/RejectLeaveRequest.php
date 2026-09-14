<?php

namespace App\Actions\Leave;

use App\Actions\Audit\RecordAuditAction;
use App\Domain\Leave\Exceptions\InvalidLeaveStateException;
use App\Domain\Leave\Exceptions\LeaveApprovalNotAuthorizedException;
use App\Domain\Leave\Rules\LeaveStateRule;
use App\Models\LeaveRequest;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Reject a pending leave request. Never consumes balance.
 */
class RejectLeaveRequest
{
    public function __construct(
        private LeaveStateRule $states,
        private RecordAuditAction $audit,
    ) {}

    public function execute(LeaveRequest $leave, User $actor, ?string $reason = null, ?Request $request = null): LeaveRequest
    {
        if (! $actor->can('leave.reject')) {
            throw new LeaveApprovalNotAuthorizedException('Actor is not authorized to reject leave.');
        }
        if ($leave->status !== 'pending') {
            throw new InvalidLeaveStateException('Only pending leave requests can be rejected.');
        }

        return DB::transaction(function () use ($leave, $actor, $reason, $request): LeaveRequest {
            $locked = LeaveRequest::whereKey($leave->id)->lockForUpdate()->firstOrFail();
            if ($locked->status !== 'pending') {
                throw new InvalidLeaveStateException('Only pending leave requests can be rejected.');
            }
            $old = ['status' => $locked->status];
            $locked->update(['status' => 'rejected', 'approved_by' => $actor->getKey(), 'approved_at' => CarbonImmutable::now(), 'rejection_reason' => $reason]);

            $this->audit->execute($actor->getKey(), 'leave.request_rejected', $locked, $old, ['status' => 'rejected'], $request, ['employee_id' => $locked->employee_id]);

            return $locked->fresh() ?? $locked;
        });
    }
}
