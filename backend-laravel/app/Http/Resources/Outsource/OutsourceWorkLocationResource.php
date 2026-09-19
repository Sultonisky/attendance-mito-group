<?php

namespace App\Http\Resources\Outsource;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class OutsourceWorkLocationResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'             => $this->id,
            'code'           => $this->code,
            'name'           => $this->name,
            'status'         => $this->status,
            'city'           => $this->city_id ? [
                'id'   => $this->city_id,
                'name' => $this->city_name,
                'code' => $this->city_code,
            ] : null,
            'address'        => trim(implode(', ', array_filter([
                $this->name,
                $this->city_name,
            ]))),
            'latitude'       => $this->latitude  !== null ? (float) $this->latitude  : null,
            'longitude'      => $this->longitude !== null ? (float) $this->longitude : null,
            'radius_meters'  => $this->radius_meters !== null ? (float) $this->radius_meters : null,
            'outsource_count'=> (int) ($this->outsource_count ?? 0),
            'created_at'     => $this->created_at ? substr((string) $this->created_at, 0, 10) : null,
        ];
    }
}
