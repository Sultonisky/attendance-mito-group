<?php

namespace App\Actions\MonthlyRecap;

use App\Actions\Audit\RecordAuditAction;
use App\Domain\MonthlyRecap\Exceptions\MonthlyRecapException;
use App\Models\MonthlyRecap;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ExportMonthlyRecap
{
    public function __construct(
        private RecordAuditAction $audit,
    ) {}

    public function execute(MonthlyRecap $recap, User $actor, ?Request $request = null): MonthlyRecap
    {
        if ($recap->status === 'exported') {
            return $recap->fresh() ?? $recap;
        }
        if ($recap->status !== 'finalized') {
            throw new MonthlyRecapException('Only finalized monthly recaps can be exported.');
        }

        return DB::transaction(function () use ($recap, $actor, $request): MonthlyRecap {
            $locked = MonthlyRecap::whereKey($recap->id)->lockForUpdate()->firstOrFail();
            if ($locked->status === 'exported') {
                return $locked;
            }
            if ($locked->status !== 'finalized') {
                throw new MonthlyRecapException('Only finalized monthly recaps can be exported.');
            }

            $now = CarbonImmutable::now();
            $old = ['status' => $locked->status];
            $locked->update([
                'status' => 'exported',
                'exported_at' => $now,
            ]);

            $this->audit->execute(
                $actor->getKey(),
                'monthly_recap.exported',
                $locked,
                $old,
                ['status' => 'exported', 'exported_at' => $now->toDateTimeString()],
                $request,
                ['employee_id' => $locked->employee_id]
            );

            return $locked->fresh() ?? $locked;
        });
    }
}
