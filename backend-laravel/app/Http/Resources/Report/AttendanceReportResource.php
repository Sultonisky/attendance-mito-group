<?php

namespace App\Http\Resources\Report;

use App\Support\AttendanceDateTime;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AttendanceReportResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'employee_id' => $this->employee_id,
            'employee_name' => $this->employee_name,
            'attendance_date' => $this->attendance_date?->toDateString(),
            'status' => $this->status,
            'check_in_at' => AttendanceDateTime::toApi($this->first_check_in ?? null),
            'check_out_at' => AttendanceDateTime::toApi($this->last_check_out ?? null),
            'duration_minutes' => $this->total_duration !== null ? (int) $this->total_duration : null,
            'created_at' => AttendanceDateTime::toApi($this->created_at),
        ];
    }
}
