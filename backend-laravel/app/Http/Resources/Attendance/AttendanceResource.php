<?php

namespace App\Http\Resources\Attendance;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Standardized JSON response for attendance records.
 */
class AttendanceResource extends JsonResource
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
                'employee_id' => $data['employee_id'] ?? null,
                'attendance_date' => $data['attendance_date'] ?? null,
                'status' => $data['status'] ?? null,
                'sessions' => isset($data['sessions']) && is_array($data['sessions'])
                    ? AttendanceSessionResource::collection($data['sessions'])
                    : [],
                'geofence' => $data['geofence'] ?? null,
                'policy' => $data['policy'] ?? null,
                'error' => $data['error'] ?? null,
                'created_at' => $data['created_at'] ?? null,
                'updated_at' => $data['updated_at'] ?? null,
            ],
        ];
    }
}
