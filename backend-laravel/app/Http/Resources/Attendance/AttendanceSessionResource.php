<?php

namespace App\Http\Resources\Attendance;

use App\Support\AttendanceDateTime;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Standardized JSON response for attendance sessions.
 */
class AttendanceSessionResource extends JsonResource
{
    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $data = $this->resource;

        return [
            'success' => true,
            'data' => [
                'id' => $data['id'] ?? null,
                'attendance_record_id' => $data['attendance_record_id'] ?? null,
                'check_in_at' => AttendanceDateTime::toApi($data['check_in_at'] ?? null),
                'check_out_at' => AttendanceDateTime::toApi($data['check_out_at'] ?? null),
                'duration_minutes' => $data['duration_minutes'] ?? null,
                'status' => $data['status'] ?? null,
                'created_at' => AttendanceDateTime::toApi($data['created_at'] ?? null),
                'updated_at' => AttendanceDateTime::toApi($data['updated_at'] ?? null),
            ],
        ];
    }
}
