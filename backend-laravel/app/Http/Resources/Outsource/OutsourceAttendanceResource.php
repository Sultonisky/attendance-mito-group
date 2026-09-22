<?php

namespace App\Http\Resources\Outsource;

use App\Support\AttendanceDateTime;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class OutsourceAttendanceResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $attendanceDate = $this->resource['attendance_date'] ?? null;
        if ($attendanceDate instanceof DateTimeInterface) {
            $attendanceDate = CarbonImmutable::parse($attendanceDate)->toDateString();
        }

        return [
            'success' => true,
            'data' => [
                'attendance_id' => $this->resource['id'] ?? null,
                'status' => $this->resource['status'] ?? null,
                'attendance_date' => $attendanceDate,
                'check_in_at' => AttendanceDateTime::toApi($this->resource['check_in_at'] ?? null),
                'check_out_at' => AttendanceDateTime::toApi($this->resource['check_out_at'] ?? null),
                'duration_minutes' => $this->resource['duration_minutes'] ?? null,
            ],
        ];
    }
}
