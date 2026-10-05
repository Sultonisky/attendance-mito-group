<?php

namespace App\Actions\Employee;

use App\Actions\Action;
use App\Actions\Audit\RecordAuditAction;
use App\Models\EmployeeWorkLocation;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class DeleteEmployeeWorkLocation implements Action
{
    public function __construct(
        private readonly RecordAuditAction $audit,
    ) {}

    public function execute(EmployeeWorkLocation $location, ?User $actor, ?Request $request = null): void
    {
        DB::transaction(function () use ($location, $actor, $request): void {
            $snapshot = [
                'code' => $location->code,
                'name' => $location->name,
                'city' => $location->city,
                'area_type' => $location->area_type?->value,
            ];

            $activeAssignments = DB::table('employee_work_location_assignments')
                ->where('employee_work_location_id', $location->id)
                ->where('status', 'active');

            $deactivatedEmployeeIds = (clone $activeAssignments)
                ->pluck('employee_id')
                ->map(fn ($id) => (int) $id)
                ->all();

            if ($deactivatedEmployeeIds !== []) {
                $activeAssignments->update(['status' => 'inactive', 'updated_at' => now()]);
            }

            $snapshot['deactivated_employee_ids'] = $deactivatedEmployeeIds;

            $location->delete();

            $this->audit->execute(
                $actor?->getKey(),
                'employee_work_location.deleted',
                $location,
                $snapshot,
                null,
                $request,
            );
        });
    }
}
