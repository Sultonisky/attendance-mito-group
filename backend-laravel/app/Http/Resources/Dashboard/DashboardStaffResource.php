<?php

namespace App\Http\Resources\Dashboard;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class DashboardStaffResource extends JsonResource
{
    /**
     * @param  array<string, mixed>  $resource
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->resource['id'],
            'code' => $this->resource['code'] ?? '',
            'name' => $this->resource['name'],
            'email' => $this->resource['email'],
            'location' => $this->resource['location'],
            'status' => $this->resource['status'],
        ];
    }
}
