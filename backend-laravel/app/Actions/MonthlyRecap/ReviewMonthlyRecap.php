<?php

namespace App\Actions\MonthlyRecap;

use App\Actions\Audit\RecordAuditAction;
use App\Domain\MonthlyRecap\Exceptions\MonthlyRecapException;
use App\Enums\MonthlyRecapStatus;
use App\Models\MonthlyRecap;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ReviewMonthlyRecap
{
    public function __construct(
        private RecordAuditAction $audit,
    ) {}

    public function execute(MonthlyRecap $recap, User $actor, ?Request $request = null): MonthlyRecap
    {
        $status = MonthlyRecapStatus::normalize($recap->status);

        if ($status === MonthlyRecapStatus::Review->value) {
            if ($recap->status !== MonthlyRecapStatus::Review->value) {
                $recap->update(['status' => MonthlyRecapStatus::Review->value]);
            }

            return $recap->fresh() ?? $recap;
        }
        if ($status !== MonthlyRecapStatus::Draft->value) {
            throw new MonthlyRecapException('Only draft monthly recaps can be moved to review.');
        }

        return DB::transaction(function () use ($recap, $actor, $request): MonthlyRecap {
            $locked = MonthlyRecap::whereKey($recap->id)->lockForUpdate()->firstOrFail();
            $lockedStatus = MonthlyRecapStatus::normalize($locked->status);

            if ($lockedStatus === MonthlyRecapStatus::Review->value) {
                if ($locked->status !== MonthlyRecapStatus::Review->value) {
                    $locked->update(['status' => MonthlyRecapStatus::Review->value]);
                }

                return $locked->fresh() ?? $locked;
            }
            if ($lockedStatus !== MonthlyRecapStatus::Draft->value) {
                throw new MonthlyRecapException('Only draft monthly recaps can be moved to review.');
            }

            $old = ['status' => $locked->status];
            $locked->update(['status' => MonthlyRecapStatus::Review->value]);

            $this->audit->execute(
                $actor->getKey(),
                'monthly_recap.reviewed',
                $locked,
                $old,
                ['status' => MonthlyRecapStatus::Review->value],
                $request,
                ['employee_id' => $locked->employee_id, 'outsource_id' => $locked->outsource_id]
            );

            return $locked->fresh() ?? $locked;
        });
    }
}
