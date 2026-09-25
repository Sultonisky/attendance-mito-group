<?php

namespace App\Actions\MonthlyRecap;

use App\Actions\Audit\RecordAuditAction;
use App\Domain\MonthlyRecap\DTOs\MonthlyRecapData;
use App\Domain\MonthlyRecap\Engines\MonthlyRecapEngine;
use App\Domain\MonthlyRecap\Exceptions\MonthlyRecapException;
use App\Models\Employee;
use App\Models\MonthlyRecap;
use App\Models\MonthlyRecapDetail;
use App\Models\Outsource;
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
        $existing = MonthlyRecap::query()
            ->where('source', 'employee')
            ->where('employee_id', $employee->id)
            ->where('period', $period)
            ->first();

        $this->guardLocked($existing);

        return DB::transaction(function () use ($employee, $periodStart, $periodEnd, $actor, $request, $period, $existing): MonthlyRecap {
            $locked = $this->lockOrCreate($existing, [
                'employee_id' => $employee->id,
                'outsource_id' => null,
                'source' => 'employee',
                'period' => $period,
            ]);

            $data = $this->engine->generate($employee, $periodStart, $periodEnd);
            $this->persistAttendanceSummary($locked, $data, $existing?->status, $actor, $request, [
                'employee_id' => $employee->id,
                'source' => 'employee',
            ]);

            return $locked->fresh() ?? $locked;
        });
    }

    public function executeForOutsource(Outsource $outsource, CarbonImmutable $periodStart, CarbonImmutable $periodEnd, User $actor, ?Request $request = null): MonthlyRecap
    {
        $period = $periodStart->format('Y-m');
        $existing = MonthlyRecap::query()
            ->where('source', 'outsource')
            ->where('outsource_id', $outsource->id)
            ->where('period', $period)
            ->first();

        $this->guardLocked($existing);

        return DB::transaction(function () use ($outsource, $periodStart, $periodEnd, $actor, $request, $period, $existing): MonthlyRecap {
            $locked = $this->lockOrCreate($existing, [
                'employee_id' => null,
                'outsource_id' => $outsource->id,
                'source' => 'outsource',
                'period' => $period,
            ]);

            $data = $this->engine->generateForOutsource($outsource, $periodStart, $periodEnd);
            $this->persistAttendanceSummary($locked, $data, $existing?->status, $actor, $request, [
                'outsource_id' => $outsource->id,
                'source' => 'outsource',
            ]);

            return $locked->fresh() ?? $locked;
        });
    }

    private function guardLocked(?MonthlyRecap $existing): void
    {
        if ($existing !== null && in_array($existing->status, ['finalized', 'exported'], true)) {
            throw new MonthlyRecapException('Cannot regenerate a finalized or exported monthly recap. Reopen it first.');
        }
    }

    /**
     * @param  array{employee_id: int|null, outsource_id: int|null, source: string, period: string}  $createAttrs
     */
    private function lockOrCreate(?MonthlyRecap $existing, array $createAttrs): MonthlyRecap
    {
        if ($existing !== null) {
            $locked = MonthlyRecap::whereKey($existing->id)->lockForUpdate()->firstOrFail();
            $locked->details()->delete();

            return $locked;
        }

        return MonthlyRecap::create([
            ...$createAttrs,
            'status' => 'draft',
            'summary' => [],
        ]);
    }

    /**
     * @param  array<string, mixed>  $auditContext
     */
    private function persistAttendanceSummary(
        MonthlyRecap $locked,
        MonthlyRecapData $data,
        ?string $previousStatus,
        User $actor,
        ?Request $request,
        array $auditContext,
    ): void {
        $summary = [
            'scheduled_days' => $data->scheduledDays,
            'present_days' => $data->presentDays,
            'late_days' => $data->lateDays,
            'incomplete_days' => $data->incompleteDays,
            'absent_days' => $data->absentDays,
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
            ['status' => $previousStatus],
            ['status' => 'draft', 'period' => $locked->period, 'source' => $locked->source],
            $request,
            $auditContext,
        );
    }
}
