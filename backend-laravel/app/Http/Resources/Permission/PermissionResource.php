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
            'users_count' => \App\Models\User::query()
                ->whereHas('permissions', fn ($q) => $q->where('permissions.id', $this->resource->id))
                ->orWhereHas('roles.permissions', fn ($q) => $q->where('permissions.id', $this->resource->id))
                ->distinct()
                ->count(),
            'created_at' => $this->resource->created_at?->toDateString(),
            'updated_at' => $this->resource->updated_at?->toDateString(),
        ];
    }
}
