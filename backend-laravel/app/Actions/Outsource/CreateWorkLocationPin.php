<?php

namespace App\Actions\Outsource;

use App\Actions\Action;
use App\Actions\Audit\RecordAuditAction;
use App\Models\User;
use App\Models\WorkLocation;
use App\Models\WorkLocationPin;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class CreateWorkLocationPin implements Action
{
    public function __construct(
        private readonly RecordAuditAction $audit,
    ) {}

    /**
     * @param  array{name: string, address?: string|null, latitude: float, longitude: float, radius_meters?: float|null, status?: string}  $input
     */
    public function execute(WorkLocation $location, array $input, ?User $actor, ?Request $request = null): WorkLocationPin
    {
        return DB::transaction(function () use ($location, $input, $actor, $request): WorkLocationPin {
            $attributes = [
                'work_location_id' => $location->id,
                'name' => $input['name'],
                'address' => $input['address'] ?? null,
                'latitude' => $input['latitude'],
                'longitude' => $input['longitude'],
                'radius_meters' => $input['radius_meters'] ?? null,
                'status' => $input['status'] ?? 'active',
            ];

            $pin = WorkLocationPin::create($attributes);
            $this->syncLocationPoint($pin, (float) $input['latitude'], (float) $input['longitude']);

            $this->audit->execute(
                $actor?->getKey(),
                'work_location_pin.created',
                $pin,
                null,
                $attributes,
                $request,
            );

            return $pin->fresh();
        });
    }

    private function syncLocationPoint(WorkLocationPin $pin, float $lat, float $lng): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement(
                'UPDATE work_location_pins SET location_point = ST_SetSRID(ST_MakePoint(?, ?), 4326) WHERE id = ?',
                [$lng, $lat, $pin->id],
            );
        }
    }
}
