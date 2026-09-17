<?php

namespace App\Http\Resources\Report;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class OutsourceAttendanceReportResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'attendance_id' => $this->id,
            'outsource' => [
                'id' => $this->outsource_id,
                'name' => $this->outsource_name,
                'code' => $this->outsource_code,
            ],
            'city' => $this->city_name ? [
                'id' => null,
                'name' => $this->city_name,
            ] : null,
            'store' => $this->store_id ? [
                'id' => (int) $this->store_id,
                'name' => $this->store_name,
            ] : null,
            'attendance_date' => $this->attendance_date?->toDateString(),
            'status' => $this->status,
            'check_in_at' => $this->first_check_in,
            'check_out_at' => $this->last_check_out,
            'duration_minutes' => $this->total_duration ? (int) $this->total_duration : null,
        ];
    }
}
