<?php

namespace App\Http\Resources\Employee;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class EmployeeWorkLocationResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->resource->id,
            'code' => $this->resource->code,
            'name' => $this->resource->name,
            'city' => $this->resource->city,
            'area_type' => $this->resource->area_type?->value,
            'area_type_label' => $this->resource->area_type?->label(),
            'address' => $this->resource->address,
            'latitude' => $this->resource->latitude,
            'longitude' => $this->resource->longitude,
            'radius_meters' => $this->resource->radius_meters,
            'status' => $this->resource->status,
            'employee_count' => $this->when(
                $this->resource->employee_count !== null,
                fn () => (int) $this->resource->employee_count,
            ),
            'created_at' => $this->resource->created_at?->toDateString(),
            'updated_at' => $this->resource->updated_at?->toDateString(),
        ];
    }
}
