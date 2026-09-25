<?php

namespace App\Actions\MonthlyRecap;

use App\Actions\Audit\RecordAuditAction;
use App\Domain\MonthlyRecap\Exceptions\MonthlyRecapException;
use App\Enums\MonthlyRecapStatus;
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
        $status = MonthlyRecapStatus::normalize($recap->status);

        if ($status === MonthlyRecapStatus::Review->value) {
            if ($recap->status !== MonthlyRecapStatus::Review->value) {
                $recap->update(['status' => MonthlyRecapStatus::Review->value]);
            }

            return $recap->fresh() ?? $recap;
        }
        if (! in_array($status, [MonthlyRecapStatus::Finalized->value, MonthlyRecapStatus::Exported->value], true)) {
            throw new MonthlyRecapException('Only finalized or exported monthly recaps can be reopened.');
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
            if (! in_array($lockedStatus, [MonthlyRecapStatus::Finalized->value, MonthlyRecapStatus::Exported->value], true)) {
                throw new MonthlyRecapException('Only finalized or exported monthly recaps can be reopened.');
            }

            $old = [
                'status' => $locked->status,
                'finalized_at' => $locked->finalized_at,
                'exported_at' => $locked->exported_at,
            ];
            $locked->update([
                'status' => MonthlyRecapStatus::Review->value,
                'finalized_at' => null,
                'exported_at' => null,
            ]);

            $this->audit->execute(
                $actor->getKey(),
                'monthly_recap.reopened',
                $locked,
                $old,
                ['status' => MonthlyRecapStatus::Review->value, 'finalized_at' => null, 'exported_at' => null],
                $request,
                ['employee_id' => $locked->employee_id, 'outsource_id' => $locked->outsource_id]
            );

            return $locked->fresh() ?? $locked;
        });
    }
}
