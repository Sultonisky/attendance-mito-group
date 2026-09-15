<?php

namespace App\Actions\MonthlyRecap;

use App\Actions\Audit\RecordAuditAction;
use App\Domain\MonthlyRecap\Exceptions\MonthlyRecapException;
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
        if ($recap->status === 'review') {
            return $recap->fresh() ?? $recap;
        }
        if ($recap->status !== 'draft') {
            throw new MonthlyRecapException('Only draft monthly recaps can be moved to review.');
        }

        return DB::transaction(function () use ($recap, $actor, $request): MonthlyRecap {
            $locked = MonthlyRecap::whereKey($recap->id)->lockForUpdate()->firstOrFail();
            if ($locked->status === 'review') {
                return $locked;
            }
            if ($locked->status !== 'draft') {
                throw new MonthlyRecapException('Only draft monthly recaps can be moved to review.');
            }

            $old = ['status' => $locked->status];
            $locked->update(['status' => 'review']);

            $this->audit->execute(
                $actor->getKey(),
                'monthly_recap.reviewed',
                $locked,
                $old,
                ['status' => 'review'],
                $request,
                ['employee_id' => $locked->employee_id]
            );

            return $locked->fresh() ?? $locked;
        });
    }
}
