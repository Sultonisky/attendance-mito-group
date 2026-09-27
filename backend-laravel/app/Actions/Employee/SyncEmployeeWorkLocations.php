<?php

namespace App\Actions\Employee;

use App\Actions\Action;
use App\Actions\Audit\RecordAuditAction;
use App\Models\Employee;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class SyncEmployeeWorkLocations implements Action
{
    public function __construct(
        private readonly RecordAuditAction $audit,
    ) {}

    /**
     * Replace the employee's active work location assignments.
     * Removed assignments are kept as inactive rows for history.
     *
     * @param  list<int>  $workLocationIds
     * @return list<int> active work location ids after sync
     */
    public function execute(Employee $employee, array $workLocationIds, ?User $actor, ?Request $request = null): array
    {
        return DB::transaction(function () use ($employee, $workLocationIds, $actor, $request): array {
            $targetIds = array_values(array_unique(array_map('intval', $workLocationIds)));
            sort($targetIds);

            $previousIds = $this->activeIds($employee);

            if ($previousIds === $targetIds) {
                return $targetIds;
            }

            $existingIds = $employee->workLocations()->allRelatedIds()->map(fn ($id) => (int) $id)->all();

            if ($existingIds !== []) {
                $employee->workLocations()->updateExistingPivot($existingIds, ['status' => 'inactive']);
            }

            foreach ($targetIds as $locationId) {
                if (in_array($locationId, $existingIds, true)) {
                    $employee->workLocations()->updateExistingPivot($locationId, ['status' => 'active']);
                } else {
                    $employee->workLocations()->attach($locationId, ['status' => 'active']);
                }
            }

            $this->audit->execute(
                $actor?->getKey(),
                'employee.work_locations_synced',
                $employee,
                ['work_location_ids' => $previousIds],
                ['work_location_ids' => $targetIds],
                $request,
            );

            return $targetIds;
        });
    }

    /**
     * @return list<int>
     */
    private function activeIds(Employee $employee): array
    {
        $ids = $employee->activeWorkLocations()
            ->pluck('employee_work_locations.id')
            ->map(fn ($id) => (int) $id)
            ->all();
        sort($ids);

        return $ids;
    }
}
