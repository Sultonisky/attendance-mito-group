<?php

namespace App\Actions\Employee;

use App\Actions\Action;
use App\Actions\Audit\RecordAuditAction;
use App\Models\EmployeeWorkLocation;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class UpdateEmployeeWorkLocation implements Action
{
    private const AUDITED = ['code', 'name', 'city', 'area_type', 'address', 'latitude', 'longitude', 'radius_meters', 'status'];

    public function __construct(
        private readonly RecordAuditAction $audit,
    ) {}

    /**
     * @param  array<string, mixed>  $input
     */
    public function execute(EmployeeWorkLocation $location, array $input, ?User $actor, ?Request $request = null): EmployeeWorkLocation
    {
        return DB::transaction(function () use ($location, $input, $actor, $request): EmployeeWorkLocation {
            $before = $this->snapshot($location);

            foreach (['name', 'city'] as $key) {
                if (array_key_exists($key, $input) && is_string($input[$key])) {
                    $input[$key] = trim($input[$key]);
                }
            }

            if (array_key_exists('radius_meters', $input) && $input['radius_meters'] === null) {
                $input['radius_meters'] = EmployeeWorkLocation::DEFAULT_RADIUS_METERS;
            }

            $location->update($input);

            if (array_key_exists('latitude', $input) || array_key_exists('longitude', $input)) {
                $location->syncLocationPoint();
            }

            $this->audit->execute(
                $actor?->getKey(),
                'employee_work_location.updated',
                $location,
                $before,
                $this->snapshot($location),
                $request,
            );

            return $location->refresh();
        });
    }

    /**
     * @return array<string, mixed>
     */
    private function snapshot(EmployeeWorkLocation $location): array
    {
        $data = $location->only(self::AUDITED);

        return $data;
    }
}
