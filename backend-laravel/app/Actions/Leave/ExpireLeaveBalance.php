<?php

namespace App\Actions\Leave;

use App\Actions\Audit\RecordAuditAction;
use App\Domain\Leave\Engines\LeaveEngine;
use App\Models\Employee;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Expire stale annual batches for one employee inside a transaction + audit.
 */
class ExpireLeaveBalance
{
    public function __construct(
        private LeaveEngine $engine,
        private RecordAuditAction $audit,
    ) {}

    public function execute(Employee $employee, ?CarbonImmutable $asOf = null, ?int $actorId = null): int
    {
        $asOf ??= CarbonImmutable::now();

        return DB::transaction(function () use ($employee, $asOf, $actorId): int {
            $expired = $this->engine->expire($employee, $asOf, $actorId);
            foreach ($expired as $transaction) {
                $this->audit->execute($actorId, 'leave.expired', $transaction, null, ['period' => $transaction->metadata['period'] ?? null, 'amount' => $transaction->amount], null, ['employee_id' => $employee->id]);
            }

            return count($expired);
        });
    }
}
