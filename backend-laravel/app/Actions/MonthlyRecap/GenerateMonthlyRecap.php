<?php

namespace App\Actions\MonthlyRecap;

use App\Actions\Audit\RecordAuditAction;
use App\Domain\MonthlyRecap\Engines\MonthlyRecapEngine;
use App\Domain\MonthlyRecap\Exceptions\MonthlyRecapException;
use App\Models\Employee;
use App\Models\MonthlyRecap;
use App\Models\MonthlyRecapDetail;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class GenerateMonthlyRecap
{
    public function __construct(
        private RecordAuditAction $audit,
        private MonthlyRecapEngine $engine,
    ) {}

    public function execute(Employee $employee, CarbonImmutable $periodStart, CarbonImmutable $periodEnd, User $actor, ?Request $request = null): MonthlyRecap
    {
        $period = $periodStart->format('Y-m');

        $existing = MonthlyRecap::where('employee_id', $employee->id)
            ->where('period', $period)
            ->first();

        if ($existing !== null && in_array($existing->status, ['finalized', 'exported'], true)) {
            throw new MonthlyRecapException('Cannot regenerate a finalized or exported monthly recap. Reopen it first.');
        }

        return DB::transaction(function () use ($employee, $periodStart, $periodEnd, $actor, $request, $period, $existing): MonthlyRecap {
            if ($existing !== null) {
                $locked = MonthlyRecap::whereKey($existing->id)->lockForUpdate()->firstOrFail();
                $locked->details()->delete();
            } else {
                $locked = MonthlyRecap::create([
                    'employee_id' => $employee->id,
                    'period' => $period,
                    'status' => 'draft',
                    'summary' => [],
                ]);
            }

            $data = $this->engine->generate($employee, $periodStart, $periodEnd);

            $summary = [
                'scheduled_days' => $data->scheduledDays,
                'present_days' => $data->presentDays,
                'late_days' => $data->lateDays,
                'incomplete_days' => $data->incompleteDays,
                'absent_days' => $data->absentDays,
                'leave_days' => $data->leaveDays,
                'business_trip_days' => $data->businessTripDays,
                'overtime_approved_minutes' => $data->overtimeApprovedMinutes,
                'overtime_potential_minutes' => $data->overtimePotentialMinutes,
                'penalty_points' => $data->penaltyPoints,
                'penalty_count' => $data->penaltyCount,
            ];

            $locked->update([
                'status' => 'draft',
                'summary' => $summary,
            ]);

            foreach ($data->details as $detailData) {
                MonthlyRecapDetail::create([
                    'monthly_recap_id' => $locked->id,
                    'detail_type' => $detailData->detailType,
                    'category' => $detailData->category,
                    'value' => $detailData->value,
                    'quantity' => $detailData->quantity,
                    'metadata' => $detailData->metadata,
                ]);
            }

            $this->audit->execute(
                $actor->getKey(),
                'monthly_recap.generated',
                $locked,
                ['status' => $existing?->status ?? null],
                ['status' => 'draft', 'period' => $period],
                $request,
                ['employee_id' => $employee->id]
            );

            return $locked->fresh() ?? $locked;
        });
    }
}
