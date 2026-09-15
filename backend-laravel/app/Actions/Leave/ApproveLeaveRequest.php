<?php

namespace App\Actions\Leave;

use App\Actions\Audit\RecordAuditAction;
use App\Domain\Leave\Engines\LeaveEngine;
use App\Domain\Leave\Exceptions\InvalidLeaveStateException;
use App\Domain\Leave\Exceptions\LeaveApprovalNotAuthorizedException;
use App\Domain\Leave\Rules\LeaveDateRule;
use App\Domain\Leave\Rules\LeaveStateRule;
use App\Models\LeaveRequest;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Approve a pending leave request atomically.
 *
 * Revalidates overlap, eligibility, and annual balance inside the same
 * transaction that consumes balance and writes audit. State-safe: approving
 * an already-approved request is a no-op returning the request.
 */
class ApproveLeaveRequest
{
    public function __construct(
        private LeaveEngine $engine,
        private LeaveDateRule $dates,
        private LeaveStateRule $states,
        private RecordAuditAction $audit,
    ) {}

    public function execute(LeaveRequest $leave, User $actor, ?Request $request = null): LeaveRequest
    {
        $this->authorize($leave, $actor);

        if ($leave->status === 'approved') {
            return $leave->fresh() ?? $leave;
        }
        if ($leave->status !== 'pending') {
            throw new InvalidLeaveStateException('Only pending leave requests can be approved.');
        }

        return DB::transaction(function () use ($leave, $actor, $request): LeaveRequest {
            $locked = LeaveRequest::whereKey($leave->id)->lockForUpdate()->firstOrFail();
            if ($locked->status === 'approved') {
                return $locked;
            }
            if ($locked->status !== 'pending') {
                throw new InvalidLeaveStateException('Only pending leave requests can be approved.');
            }

            $employee = $locked->employee()->firstOrFail();
            $type = $locked->leaveType()->firstOrFail();
            $start = CarbonImmutable::parse($locked->start_date)->startOfDay();
            $end = CarbonImmutable::parse($locked->end_date)->startOfDay();
            $duration = $this->dates->duration($start, $end);
            $now = CarbonImmutable::now();

            $this->engine->assertNoOverlap($employee, $start, $end, $locked->id);

            // Expire stale batches then accrue missing months before checking balance.
            $this->engine->expire($employee, $now, $actor->getKey());
            $eligibility = $this->engine->resolveEligibility($employee, $now);
            $consumesAnnual = $this->engine->consumesAnnualBalance($type);

            if ($consumesAnnual) {
                if (! $eligibility->eligible) {
                    throw new InvalidLeaveStateException('Employee is not eligible for annual leave.');
                }
                $this->engine->accrue($employee, $now, $actor->getKey());
            }

            $old = ['status' => $locked->status];
            $locked->update(['status' => 'approved', 'approved_by' => $actor->getKey(), 'approved_at' => $now, 'rejection_reason' => null]);

            if ($consumesAnnual) {
                $annual = $this->engine->annualLeaveType();
                $this->engine->consume($employee, $annual, $duration->totalDays, $locked, $now);
            }

            $this->audit->execute(
                $actor->getKey(),
                'leave.request_approved',
                $locked,
                $old,
                ['status' => 'approved', 'days' => $duration->totalDays],
                $request,
                ['employee_id' => $employee->id]
            );

            return $locked->fresh() ?? $locked;
        });
    }

    private function authorize(LeaveRequest $leave, User $actor): void
    {
        if (! $actor->can('leave.approve')) {
            throw new LeaveApprovalNotAuthorizedException('Actor is not authorized to approve leave.');
        }
        $employee = $leave->employee()->first();
        if ($employee !== null && (int) $employee->getAttribute('user_id') === (int) $actor->getKey()) {
            throw new LeaveApprovalNotAuthorizedException('Self-approval of leave is not allowed.');
        }
    }
}
