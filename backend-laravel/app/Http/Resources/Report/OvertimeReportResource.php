<?php

namespace App\Http\Resources\Report;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class OvertimeReportResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'employee_id' => $this->employee_id,
            'employee_name' => $this->employee_name,
            'attendance_id' => $this->attendance_id,
            'overtime_request_id' => $this->overtime_request_id,
            'date' => $this->date?->toDateString(),
            'potential_minutes' => $this->potential_minutes,
            'requested_minutes' => $this->requested_minutes,
            'approved_minutes' => $this->approved_minutes,
            'actual_minutes' => $this->actual_minutes,
            'status' => $this->status,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
