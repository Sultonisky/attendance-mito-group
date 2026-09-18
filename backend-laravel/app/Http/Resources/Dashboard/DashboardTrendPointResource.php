<?php

namespace App\Http\Resources\Dashboard;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class DashboardTrendPointResource extends JsonResource
{
    /**
     * @param  array<string, mixed>  $resource
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'date' => $this->resource['date'],
            'present' => $this->resource['present'],
            'absent' => $this->resource['absent'],
            'late' => $this->resource['late'],
            'on_leave' => $this->resource['on_leave'],
            'rate' => $this->resource['rate'],
        ];
    }
}
