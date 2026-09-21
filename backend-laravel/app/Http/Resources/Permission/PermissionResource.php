<?php

namespace App\Http\Resources\Permission;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PermissionResource extends JsonResource
{
    /**
     * @param  array<string, mixed>  $resource
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->resource->id,
            'name' => $this->resource->name,
            'guard_name' => $this->resource->guard_name,
            'description' => $this->resource->description,
            'created_at' => $this->resource->created_at?->toDateString(),
            'updated_at' => $this->resource->updated_at?->toDateString(),
        ];
    }
}
