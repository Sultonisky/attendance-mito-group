<?php

namespace App\Services\Attendance;

use App\Models\Employee;
use App\Models\EmployeeWorkLocation;
use Illuminate\Support\Collection;
use InvalidArgumentException;

/**
 * Resolves the work locations an employee may use as check-in/out geofence.
 *
 * Rules (mirrors outsource cabang/pin resolution):
 * - Employee needs at least one active assignment
 * - Only active, non-deleted locations with coordinates are usable
 * - Exactly one usable location => auto-selected when none is submitted
 * - Cross-location IN/OUT is allowed
 */
class ResolveEmployeeAllowedWorkLocations
{
    /**
     * @return Collection<int, EmployeeWorkLocation>
     */
    public function execute(Employee $employee): Collection
    {
        $assigned = $employee->activeWorkLocations()->get();

        if ($assigned->isEmpty()) {
            throw new InvalidArgumentException('Employee has no active work location assignment.');
        }

        return $assigned
            ->filter(fn (EmployeeWorkLocation $location) => $location->status === 'active'
                && $location->latitude !== null
                && $location->longitude !== null)
            ->sortBy([
                ['city', 'asc'],
                ['name', 'asc'],
                ['id', 'asc'],
            ])
            ->values();
    }

    public function resolveForAttendance(Employee $employee, ?int $workLocationId): EmployeeWorkLocation
    {
        $allowed = $this->execute($employee);

        if ($allowed->isEmpty()) {
            throw new InvalidArgumentException('Assigned work location is inactive or has no coordinates configured.');
        }

        if ($workLocationId === null) {
            if ($allowed->count() === 1) {
                return $allowed->first();
            }

            throw new InvalidArgumentException('Work location is required for attendance.');
        }

        $location = $allowed->firstWhere('id', $workLocationId);

        if ($location === null) {
            throw new InvalidArgumentException('Work location is not assigned to this employee.');
        }

        return $location;
    }
}
