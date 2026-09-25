<?php

namespace App\Services\Outsource;

use App\Models\Outsource;
use App\Models\OutsourceAssignmentPin;
use App\Models\OutsourceStoreAssignment;
use App\Models\WorkLocationPin;
use Illuminate\Support\Collection;
use InvalidArgumentException;

/**
 * Resolves active cabang assignments and allowed pins for an outsource person.
 *
 * Rules:
 * - One or more active cabang assignments (cross-cabang IN/OUT allowed)
 * - Empty pin subset on an assignment => all active pins of that cabang
 * - Non-empty subset => only those pins (must belong to that cabang)
 * - Session primary storeId is display/default only; it does not lock OUT pin
 */
class ResolveOutsourceAllowedPins
{
    /**
     * @return array{
     *   assignments: Collection<int, OutsourceStoreAssignment>,
     *   assignment: OutsourceStoreAssignment,
     *   pins: Collection<int, WorkLocationPin>
     * }
     */
    public function execute(Outsource $outsource): array
    {
        $assignments = OutsourceStoreAssignment::query()
            ->where('outsource_id', $outsource->id)
            ->where('status', 'active')
            ->whereNull('deleted_at')
            ->orderBy('id')
            ->get();

        if ($assignments->isEmpty()) {
            throw new InvalidArgumentException('Outsource has no active cabang assignment.');
        }

        $pins = $assignments
            ->flatMap(fn (OutsourceStoreAssignment $assignment) => $this->pinsForAssignment($assignment))
            ->unique('id')
            ->sortBy([
                ['name', 'asc'],
                ['id', 'asc'],
            ])
            ->values();

        return [
            'assignments' => $assignments,
            'assignment' => $assignments->first(),
            'pins' => $pins,
        ];
    }

    /**
     * @return Collection<int, WorkLocationPin>
     */
    public function pinsForAssignment(OutsourceStoreAssignment $assignment): Collection
    {
        $subsetIds = OutsourceAssignmentPin::query()
            ->where('assignment_id', $assignment->id)
            ->pluck('pin_id');

        $query = WorkLocationPin::query()
            ->with(['workLocation.city'])
            ->where('work_location_id', $assignment->store_id)
            ->where('status', 'active')
            ->whereNull('deleted_at')
            ->orderBy('name')
            ->orderBy('id');

        if ($subsetIds->isNotEmpty()) {
            $query->whereIn('id', $subsetIds->all());
        }

        return $query->get();
    }

    /**
     * @param  int|null  $expectedStoreId  Deprecated for locking; kept for call-site compat and ignored.
     */
    public function assertPinAllowed(Outsource $outsource, int $pinId, ?int $expectedStoreId = null): WorkLocationPin
    {
        $resolved = $this->execute($outsource);
        /** @var Collection<int, WorkLocationPin> $pins */
        $pins = $resolved['pins'];

        $pin = $pins->firstWhere('id', $pinId);

        if ($pin === null) {
            throw new InvalidArgumentException('Pin is not allowed for this outsource assignment.');
        }

        $allowedStoreIds = $resolved['assignments']->pluck('store_id')->map(fn ($id) => (int) $id)->all();

        if (! in_array((int) $pin->work_location_id, $allowedStoreIds, true)) {
            throw new InvalidArgumentException('Pin does not belong to an assigned cabang.');
        }

        return $pin;
    }

    /**
     * @return list<array{
     *   id: int,
     *   name: string,
     *   address: string|null,
     *   latitude: float|null,
     *   longitude: float|null,
     *   radius_meters: float,
     *   work_location_id: int,
     *   cabang_name: string|null,
     *   city_id: int|null,
     *   city_name: string|null
     * }>
     */
    public function mapPinsForApi(Collection $pins): array
    {
        return $pins->map(function (WorkLocationPin $pin) {
            $cabang = $pin->relationLoaded('workLocation') ? $pin->workLocation : $pin->workLocation()->first();
            $city = $cabang?->relationLoaded('city') ? $cabang->city : $cabang?->city;

            return [
                'id' => $pin->id,
                'name' => $pin->name,
                'address' => $pin->address,
                'latitude' => $pin->latitude !== null ? (float) $pin->latitude : null,
                'longitude' => $pin->longitude !== null ? (float) $pin->longitude : null,
                'radius_meters' => $pin->effectiveRadiusMeters(),
                'work_location_id' => (int) $pin->work_location_id,
                'cabang_name' => $cabang?->name,
                'city_id' => $cabang?->city_id !== null ? (int) $cabang->city_id : null,
                'city_name' => $city?->name,
            ];
        })->values()->all();
    }
}
