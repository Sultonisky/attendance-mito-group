<?php

namespace App\Actions\MonthlyRecap;

use App\Actions\Audit\RecordAuditAction;
use App\Domain\MonthlyRecap\Exceptions\MonthlyRecapException;
use App\Enums\MonthlyRecapStatus;
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
        $status = MonthlyRecapStatus::normalize($recap->status);

        if ($status === MonthlyRecapStatus::Finalized->value) {
            return $recap->fresh() ?? $recap;
        }
        if ($status !== MonthlyRecapStatus::Review->value) {
            throw new MonthlyRecapException('Only review monthly recaps can be finalized.');
        }

        return DB::transaction(function () use ($recap, $actor, $request): MonthlyRecap {
            $locked = MonthlyRecap::whereKey($recap->id)->lockForUpdate()->firstOrFail();
            $lockedStatus = MonthlyRecapStatus::normalize($locked->status);

            if ($lockedStatus === MonthlyRecapStatus::Finalized->value) {
                return $locked;
            }
            if ($lockedStatus !== MonthlyRecapStatus::Review->value) {
                throw new MonthlyRecapException('Only review monthly recaps can be finalized.');
            }

            $now = CarbonImmutable::now();
            $old = ['status' => $locked->status];
            $locked->update([
                'status' => MonthlyRecapStatus::Finalized->value,
                'finalized_at' => $now,
            ]);

            $this->audit->execute(
                $actor->getKey(),
                'monthly_recap.finalized',
                $locked,
                $old,
                ['status' => MonthlyRecapStatus::Finalized->value, 'finalized_at' => $now->toDateTimeString()],
                $request,
                ['employee_id' => $locked->employee_id, 'outsource_id' => $locked->outsource_id]
            );

            return $locked->fresh() ?? $locked;
        });
    }
}
