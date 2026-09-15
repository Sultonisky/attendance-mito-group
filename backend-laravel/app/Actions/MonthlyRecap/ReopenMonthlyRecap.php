<?php

namespace App\Actions\MonthlyRecap;

use App\Actions\Audit\RecordAuditAction;
use App\Domain\MonthlyRecap\Exceptions\MonthlyRecapException;
use App\Models\MonthlyRecap;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ReopenMonthlyRecap
{
    public function __construct(
        private RecordAuditAction $audit,
    ) {}

    public function execute(MonthlyRecap $recap, User $actor, ?Request $request = null): MonthlyRecap
    {
        if ($recap->status === 'review') {
            return $recap->fresh() ?? $recap;
        }
        if ($recap->status !== 'finalized') {
            throw new MonthlyRecapException('Only finalized monthly recaps can be reopened.');
        }

        return DB::transaction(function () use ($recap, $actor, $request): MonthlyRecap {
            $locked = MonthlyRecap::whereKey($recap->id)->lockForUpdate()->firstOrFail();
            if ($locked->status === 'review') {
                return $locked;
            }
            if ($locked->status !== 'finalized') {
                throw new MonthlyRecapException('Only finalized monthly recaps can be reopened.');
            }

            $old = ['status' => $locked->status, 'finalized_at' => $locked->finalized_at];
            $locked->update([
                'status' => 'review',
                'finalized_at' => null,
            ]);

            $this->audit->execute(
                $actor->getKey(),
                'monthly_recap.reopened',
                $locked,
                $old,
                ['status' => 'review', 'finalized_at' => null],
                $request,
                ['employee_id' => $locked->employee_id]
            );

            return $locked->fresh() ?? $locked;
        });
    }
}
