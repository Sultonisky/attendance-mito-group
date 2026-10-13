<?php

namespace App\Actions\Employee;

use App\Actions\Action;
use App\Actions\Audit\RecordAuditAction;
use App\Enums\RecordStatus;
use App\Models\EmployeeWorkLocation;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class CreateEmployeeWorkLocation implements Action
{
    public function __construct(
        private readonly RecordAuditAction $audit,
    ) {}

    /**
     * @param  array{name: string, city: string, area_type: string, address?: string|null, latitude: float|string, longitude: float|string, radius_meters?: float|string|null, status?: string|null}  $input
     */
    public function execute(array $input, ?User $actor, ?Request $request = null): EmployeeWorkLocation
    {
        return DB::transaction(function () use ($input, $actor, $request): EmployeeWorkLocation {
            $attributes = [
                'code' => $this->generateCode($input['name']),
                'name' => trim($input['name']),
                'city' => trim($input['city']),
                'area_type' => $input['area_type'],
                'address' => $input['address'] ?? null,
                'latitude' => (float) $input['latitude'],
                'longitude' => (float) $input['longitude'],
                'radius_meters' => isset($input['radius_meters'])
                    ? (float) $input['radius_meters']
                    : EmployeeWorkLocation::DEFAULT_RADIUS_METERS,
                'status' => $input['status'] ?? RecordStatus::Active->value,
            ];

            $location = EmployeeWorkLocation::create($attributes);
            $location->syncLocationPoint();

            $this->audit->execute(
                $actor?->getKey(),
                'employee_work_location.created',
                $location,
                null,
                $attributes,
                $request,
            );

            return $location->fresh() ?? $location;
        });
    }

    private function generateCode(string $name): string
    {
        $base = 'EWL-'.substr(md5($name.microtime()), 0, 12);
        $candidate = $base;
        $suffix = 0;

        while (EmployeeWorkLocation::withTrashed()->where('code', $candidate)->exists()) {
            $suffix++;
            $candidate = $base.'-'.$suffix;
        }

        return $candidate;
    }
}
