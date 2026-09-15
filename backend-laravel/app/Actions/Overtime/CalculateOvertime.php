<?php

namespace App\Actions\Overtime;

use App\Actions\Audit\RecordAuditAction;
use App\Domain\Overtime\Engines\OvertimeEngine;
use App\Models\Employee;
use App\Models\OvertimeRecord;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Calculate and persist potential overtime for an employee on a date.
 *
 * Idempotent by (employee_id, date). Re-running produces the same result.
 */
class CalculateOvertime
{
    public function __construct(
        private OvertimeEngine $engine,
        private RecordAuditAction $audit,
    ) {}

    public function execute(Employee $employee, CarbonImmutable $date, ?User $actor = null, ?Request $request = null): OvertimeRecord
    {
        $result = $this->engine->calculateForDate($employee, $date);

        return DB::transaction(function () use ($employee, $date, $result, $actor, $request): OvertimeRecord {
            $existing = OvertimeRecord::where('employee_id', $employee->id)
                ->whereDate('date', $date->toDateString())
                ->first();

            if ($existing !== null) {
                if ($existing->status === 'potential' || $existing->status === 'actual') {
                    $existing->update([
                        'potential_minutes' => $result->potentialMinutes ?? 0,
                    ]);

                    $this->audit->execute(
                        $actor?->getKey(),
                        'overtime.potential_calculated',
                        $existing,
                        ['potential_minutes' => $existing->getOriginal('potential_minutes')],
                        ['potential_minutes' => $result->potentialMinutes ?? 0],
                        $request,
                        ['employee_id' => $employee->id]
                    );

                    return $existing->fresh() ?? $existing;
                }

                return $existing;
            }

            $record = OvertimeRecord::create([
                'employee_id' => $employee->id,
                'date' => $date->toDateString(),
                'potential_minutes' => $result->potentialMinutes ?? 0,
                'requested_minutes' => 0,
                'approved_minutes' => null,
                'actual_minutes' => null,
                'status' => $result->eligible ? 'potential' : 'potential',
            ]);

            $this->audit->execute(
                $actor?->getKey(),
                'overtime.potential_calculated',
                $record,
                null,
                ['potential_minutes' => $result->potentialMinutes ?? 0, 'eligible' => $result->eligible],
                $request,
                ['employee_id' => $employee->id]
            );

            return $record;
        });
    }
}
