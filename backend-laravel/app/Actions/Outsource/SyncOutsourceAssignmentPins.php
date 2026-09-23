<?php

namespace App\Actions\Outsource;

use App\Models\Outsource;
use App\Models\OutsourceStoreAssignment;
use App\Models\WorkLocationPin;
use InvalidArgumentException;

/**
 * Sync optional pin allowlist for an outsource cabang assignment.
 * Empty pin_ids clears the subset (= all active cabang pins).
 */
class SyncOutsourceAssignmentPins
{
    /**
     * @param  list<int>|null  $pinIds
     */
    public function execute(Outsource $person, int $storeId, ?array $pinIds): void
    {
        $assignment = OutsourceStoreAssignment::query()
            ->where('outsource_id', $person->id)
            ->where('store_id', $storeId)
            ->where('status', 'active')
            ->whereNull('deleted_at')
            ->first();

        if ($assignment === null) {
            throw new InvalidArgumentException('Active cabang assignment not found for pin sync.');
        }

        if ($pinIds === null) {
            return;
        }

        $uniquePinIds = array_values(array_unique(array_map('intval', $pinIds)));

        if ($uniquePinIds === []) {
            $assignment->pins()->sync([]);

            return;
        }

        $validCount = WorkLocationPin::query()
            ->where('work_location_id', $storeId)
            ->where('status', 'active')
            ->whereNull('deleted_at')
            ->whereIn('id', $uniquePinIds)
            ->count();

        if ($validCount !== count($uniquePinIds)) {
            throw new InvalidArgumentException('One or more pins do not belong to the assigned cabang.');
        }

        $assignment->pins()->sync($uniquePinIds);
    }
}
