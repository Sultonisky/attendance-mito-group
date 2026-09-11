<?php

namespace App\Http\Resources;

use App\Models\AttendanceSession;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AttendanceSessionResource extends JsonResource
{
    /**
     * @param  AttendanceSession  $resource
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'attendance_record_id' => $this->attendance_record_id,
            'check_in_at' => $this->check_in_at?->toIso8601String(),
            'check_out_at' => $this->check_out_at?->toIso8601String(),
            'duration_minutes' => $this->duration_minutes,
            'status' => $this->status,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
