<?php

namespace App\Actions\MonthlyRecap;

use App\Domain\MonthlyRecap\Exceptions\MonthlyRecapException;
use App\Models\MonthlyRecap;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Batch mark finalized monthly recaps as exported.
 *
 * Skips already-exported and non-finalized rows. Continues on per-row failures.
 */
class ExportMonthlyRecapsBulk
{
    public function __construct(
        private ExportMonthlyRecap $export,
    ) {}

    /**
     * @param  list<int>  $ids
     * @return array{
     *   exported: int,
     *   skipped: int,
     *   failed: int,
     *   failures: list<array{id: int, message: string}>
     * }
     */
    public function execute(array $ids, User $actor, ?Request $request = null): array
    {
        $uniqueIds = array_values(array_unique(array_map('intval', $ids)));
        $exported = 0;
        $skipped = 0;
        $failed = 0;
        $failures = [];

        if ($uniqueIds === []) {
            return compact('exported', 'skipped', 'failed', 'failures');
        }

        $recaps = MonthlyRecap::query()
            ->whereIn('id', $uniqueIds)
            ->get()
            ->keyBy('id');

        foreach ($uniqueIds as $id) {
            $recap = $recaps->get($id);
            if ($recap === null) {
                $failed++;
                $failures[] = ['id' => $id, 'message' => 'Monthly recap not found.'];

                continue;
            }

            if ($recap->status === 'exported') {
                $skipped++;

                continue;
            }

            if ($recap->status !== 'finalized') {
                $skipped++;

                continue;
            }

            try {
                $this->export->execute($recap, $actor, $request);
                $exported++;
            } catch (MonthlyRecapException $e) {
                $failed++;
                $failures[] = ['id' => $id, 'message' => $e->getMessage()];
            } catch (\Throwable $e) {
                $failed++;
                $failures[] = ['id' => $id, 'message' => $e->getMessage()];
                Log::warning('monthly_recap.bulk_export_failed', [
                    'monthly_recap_id' => $id,
                    'message' => $e->getMessage(),
                ]);
            }
        }

        return compact('exported', 'skipped', 'failed', 'failures');
    }
}
