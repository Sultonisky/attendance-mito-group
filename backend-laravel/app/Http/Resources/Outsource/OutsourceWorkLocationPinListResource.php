<?php

namespace App\Http\Resources\Outsource;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One table row = one pin/address under a cabang (city-level work location).
 */
class OutsourceWorkLocationPinListResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => (int) $this->id,
            'work_location_id' => (int) $this->work_location_id,
            'pin_name' => $this->pin_name,
            'address' => $this->address,
            'latitude' => $this->latitude !== null ? (float) $this->latitude : null,
            'longitude' => $this->longitude !== null ? (float) $this->longitude : null,
            'radius_meters' => $this->radius_meters !== null ? (float) $this->radius_meters : null,
            'status' => $this->status,
            'cabang' => [
                'id' => (int) $this->work_location_id,
                'name' => $this->cabang_name,
                'code' => $this->cabang_code,
            ],
            'city' => $this->city_id ? [
                'id' => (int) $this->city_id,
                'name' => $this->city_name,
                'code' => $this->city_code,
            ] : null,
            'outsource_count' => (int) ($this->outsource_count ?? 0),
            'created_at' => $this->created_at ? substr((string) $this->created_at, 0, 10) : null,
        ];
    }
}
