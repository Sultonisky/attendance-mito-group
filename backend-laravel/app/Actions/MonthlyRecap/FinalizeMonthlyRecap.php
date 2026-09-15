<?php

namespace App\Actions\MonthlyRecap;

use App\Actions\Audit\RecordAuditAction;
use App\Domain\MonthlyRecap\Exceptions\MonthlyRecapException;
use App\Models\MonthlyRecap;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class FinalizeMonthlyRecap
{
    public function __construct(
        private RecordAuditAction $audit,
    ) {}

    public function execute(MonthlyRecap $recap, User $actor, ?Request $request = null): MonthlyRecap
    {
        if ($recap->status === 'finalized') {
            return $recap->fresh() ?? $recap;
        }
        if ($recap->status !== 'review') {
            throw new MonthlyRecapException('Only review monthly recaps can be finalized.');
        }

        return DB::transaction(function () use ($recap, $actor, $request): MonthlyRecap {
            $locked = MonthlyRecap::whereKey($recap->id)->lockForUpdate()->firstOrFail();
            if ($locked->status === 'finalized') {
                return $locked;
            }
            if ($locked->status !== 'review') {
                throw new MonthlyRecapException('Only review monthly recaps can be finalized.');
            }

            $now = CarbonImmutable::now();
            $old = ['status' => $locked->status];
            $locked->update([
                'status' => 'finalized',
                'finalized_at' => $now,
            ]);

            $this->audit->execute(
                $actor->getKey(),
                'monthly_recap.finalized',
                $locked,
                $old,
                ['status' => 'finalized', 'finalized_at' => $now->toDateTimeString()],
                $request,
                ['employee_id' => $locked->employee_id]
            );

            return $locked->fresh() ?? $locked;
        });
    }
}
