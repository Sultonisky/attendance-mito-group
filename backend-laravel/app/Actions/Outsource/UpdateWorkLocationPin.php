<?php

namespace App\Actions\Outsource;

use App\Actions\Action;
use App\Actions\Audit\RecordAuditAction;
use App\Models\User;
use App\Models\WorkLocationPin;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class UpdateWorkLocationPin implements Action
{
    public function __construct(
        private readonly RecordAuditAction $audit,
    ) {}

    /**
     * @param  array{name?: string, address?: string|null, latitude?: float, longitude?: float, radius_meters?: float|null, status?: string}  $input
     */
    public function execute(WorkLocationPin $pin, array $input, ?User $actor, ?Request $request = null): WorkLocationPin
    {
        return DB::transaction(function () use ($pin, $input, $actor, $request): WorkLocationPin {
            $old = $pin->only(['name', 'address', 'latitude', 'longitude', 'radius_meters', 'status']);

            $fillable = [];
            foreach (['name', 'address', 'latitude', 'longitude', 'radius_meters', 'status'] as $key) {
                if (array_key_exists($key, $input)) {
                    $fillable[$key] = $input[$key];
                }
            }

            if ($fillable !== []) {
                $pin->update($fillable);
            }

            if (isset($input['latitude'], $input['longitude'])) {
                $this->syncLocationPoint($pin, (float) $input['latitude'], (float) $input['longitude']);
            }

            $this->audit->execute(
                $actor?->getKey(),
                'work_location_pin.updated',
                $pin,
                $old,
                $fillable,
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
