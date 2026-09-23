<?php

namespace App\Services\Outsource;

use App\Models\Outsource;
use App\Models\OutsourceAssignmentPin;
use App\Models\OutsourceStoreAssignment;
use App\Models\WorkLocationPin;
use Illuminate\Support\Collection;
use InvalidArgumentException;

/**
 * Resolves the active cabang assignment and allowed pins for an outsource person.
 *
 * Rules (locked):
 * - One active cabang assignment (no cross-cabang)
 * - Empty pin subset => all active pins of that cabang
 * - Non-empty subset => only those pins (must belong to the cabang)
 */
class ResolveOutsourceAllowedPins
{
    /**
     * @return array{assignment: OutsourceStoreAssignment, pins: Collection<int, WorkLocationPin>}
     */
    public function execute(Outsource $outsource): array
    {
        $assignment = OutsourceStoreAssignment::query()
            ->where('outsource_id', $outsource->id)
            ->where('status', 'active')
            ->whereNull('deleted_at')
            ->orderBy('id')
            ->first();

        if ($assignment === null) {
            throw new InvalidArgumentException('Outsource has no active cabang assignment.');
        }

        return [
            'assignment' => $assignment,
            'pins' => $this->pinsForAssignment($assignment),
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

    public function assertPinAllowed(Outsource $outsource, int $pinId, ?int $expectedStoreId = null): WorkLocationPin
    {
        $resolved = $this->execute($outsource);
        $assignment = $resolved['assignment'];
        /** @var Collection<int, WorkLocationPin> $pins */
        $pins = $resolved['pins'];

        if ($expectedStoreId !== null && (int) $assignment->store_id !== $expectedStoreId) {
            throw new InvalidArgumentException('Outsource session cabang does not match active assignment.');
        }

        $pin = $pins->firstWhere('id', $pinId);

        if ($pin === null) {
            throw new InvalidArgumentException('Pin is not allowed for this outsource assignment.');
        }

        if ((int) $pin->work_location_id !== (int) $assignment->store_id) {
            throw new InvalidArgumentException('Pin does not belong to the assigned cabang.');
        }

        return $pin;
    }
}
