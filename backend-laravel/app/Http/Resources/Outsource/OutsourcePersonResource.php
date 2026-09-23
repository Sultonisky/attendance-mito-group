<?php

namespace App\Http\Resources\Outsource;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class OutsourcePersonResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'             => $this->id,
            'outsource_code' => $this->outsource_code,
            'name'           => $this->name,
            'status'         => $this->status,
            'has_password'   => (bool) ($this->has_password ?? false),
            'city'           => $this->city_name  ? ['id' => $this->city_id,  'name' => $this->city_name]  : null,
            'store'          => $this->store_name ? ['id' => $this->store_id, 'name' => $this->store_name] : null,
            'pin_ids'        => is_array($this->pin_ids ?? null) ? array_values($this->pin_ids) : [],
            'created_at'     => $this->created_at
                ? substr((string) $this->created_at, 0, 10)
                : null,
        ];
    }
}
