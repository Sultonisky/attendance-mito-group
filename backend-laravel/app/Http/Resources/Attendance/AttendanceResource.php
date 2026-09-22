<?php

namespace App\Http\Resources\Attendance;

use App\Support\AttendanceDateTime;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Collection;

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

        $attendanceDate = $data['attendance_date'] ?? null;
        if ($attendanceDate instanceof DateTimeInterface) {
            $attendanceDate = CarbonImmutable::parse($attendanceDate)->toDateString();
        }

        return [
            'success' => true,
            'data' => [
                'id' => $data['id'] ?? null,
                'employee_id' => $data['employee_id'] ?? null,
                'attendance_date' => $attendanceDate,
                'status' => $data['status'] ?? null,
                'sessions' => $this->mapSessions($data['sessions'] ?? []),
                'geofence' => $data['geofence'] ?? null,
                'policy' => $data['policy'] ?? null,
                'error' => $data['error'] ?? null,
                'created_at' => AttendanceDateTime::toApi($data['created_at'] ?? null),
                'updated_at' => AttendanceDateTime::toApi($data['updated_at'] ?? null),
            ],
        ];
    }

    /**
     * @param  mixed  $sessions
     * @return list<array<string, mixed>>
     */
    private function mapSessions(mixed $sessions): array
    {
        return Collection::wrap($sessions)
            ->map(function ($session): array {
                return [
                    'id' => data_get($session, 'id'),
                    'attendance_record_id' => data_get($session, 'attendance_record_id'),
                    'check_in_at' => AttendanceDateTime::toApi(data_get($session, 'check_in_at')),
                    'check_out_at' => AttendanceDateTime::toApi(data_get($session, 'check_out_at')),
                    'duration_minutes' => data_get($session, 'duration_minutes'),
                    'status' => data_get($session, 'status'),
                    'created_at' => AttendanceDateTime::toApi(data_get($session, 'created_at')),
                    'updated_at' => AttendanceDateTime::toApi(data_get($session, 'updated_at')),
                ];
            })
            ->values()
            ->all();
    }
}
