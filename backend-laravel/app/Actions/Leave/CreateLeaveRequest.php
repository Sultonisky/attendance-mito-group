<?php

namespace App\Actions\Leave;

use App\Actions\Audit\RecordAuditAction;
use App\Domain\Leave\Engines\LeaveEngine;
use App\Domain\Leave\Exceptions\InvalidLeaveStateException;
use App\Domain\Leave\Exceptions\LeaveNotEligibleException;
use App\Domain\Leave\Rules\LeaveDateRule;
use App\Domain\Leave\Rules\LeaveStateRule;
use App\Models\Employee;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Create a pending leave request.
 *
 * Owns the transaction boundary: overlap validation, request persistence,
 * and audit occur atomically. Annual-balance sufficiency is revalidated at
 * approval time (not here) so balances cannot drift between request/approve.
 */
class CreateLeaveRequest
{
    public function __construct(
        private LeaveEngine $engine,
        private LeaveDateRule $dates,
        private LeaveStateRule $states,
        private RecordAuditAction $audit,
    ) {}

    /**
     * @param  array{leave_type_id: int, start_date: string, end_date: string, reason?: ?string}  $input
     */
    public function execute(Employee $employee, array $input, ?User $actor = null, ?Request $request = null): LeaveRequest
    {
        $type = LeaveType::whereKey($input['leave_type_id'])->first();
        if ($type === null || $type->status !== 'active') {
            throw new InvalidLeaveStateException('Leave type is not available.');
        }
        $start = CarbonImmutable::parse($input['start_date'])->startOfDay();
        $end = CarbonImmutable::parse($input['end_date'])->startOfDay();
        $duration = $this->dates->duration($start, $end);

        if (! $this->engine->isActive($employee)) {
            throw new LeaveNotEligibleException('Employee is not active.');
        }

        return DB::transaction(function () use ($employee, $type, $start, $end, $duration, $input, $actor, $request): LeaveRequest {
            $this->engine->assertNoOverlap($employee, $start, $end);

            $leave = LeaveRequest::create([
                'employee_id' => $employee->id,
                'leave_type_id' => $type->id,
                'status' => 'pending',
                'start_date' => $duration->startDate->toDateString(),
                'end_date' => $duration->endDate->toDateString(),
                'reason' => $input['reason'] ?? null,
            ]);

            $this->audit->execute(
                $actor?->getKey(),
                'leave.request_created',
                $leave,
                null,
                ['status' => 'pending', 'days' => $duration->totalDays],
                $request,
                ['employee_id' => $employee->id]
            );

            return $leave;
        });
    }
}
