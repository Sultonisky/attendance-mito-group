<?php

namespace App\Http\Resources\Attendance;

use App\Support\AttendanceDateTime;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin \App\Models\AttendanceCorrectionRequest
 */
class AttendanceCorrectionRequestResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'employee_id' => $this->employee_id,
            'employee_name' => $this->whenLoaded('employee', fn () => $this->employee?->full_name),
            'employee_code' => $this->whenLoaded('employee', fn () => $this->employee?->employee_code),
            'attendance_record_id' => $this->attendance_record_id,
            'attendance_session_id' => $this->attendance_session_id,
            'request_type' => $this->request_type,
            'attendance_date' => optional($this->attendance_date)?->toDateString(),
            'requested_check_in_at' => AttendanceDateTime::toApi($this->requested_check_in_at),
            'requested_check_out_at' => AttendanceDateTime::toApi($this->requested_check_out_at),
            'reason' => $this->reason,
            'status' => $this->status,
            'reviewed_by' => $this->reviewed_by,
            'reviewed_at' => AttendanceDateTime::toApi($this->reviewed_at),
            'rejection_reason' => $this->rejection_reason,
            'created_at' => AttendanceDateTime::toApi($this->created_at),
            'updated_at' => AttendanceDateTime::toApi($this->updated_at),
        ];
    }
}
