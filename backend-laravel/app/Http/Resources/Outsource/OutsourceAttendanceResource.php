<?php

namespace App\Http\Resources\Outsource;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class OutsourceAttendanceResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'success' => true,
            'data' => [
                'attendance_id' => $this->resource['id'] ?? null,
                'status' => $this->resource['status'] ?? null,
                'attendance_date' => $this->resource['attendance_date'] ?? null,
                'check_in_at' => $this->resource['check_in_at'] ?? null,
                'check_out_at' => $this->resource['check_out_at'] ?? null,
                'duration_minutes' => $this->resource['duration_minutes'] ?? null,
            ],
        ];
    }
}
