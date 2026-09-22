<?php

namespace App\Actions\Outsource;

use App\Domain\Attendance\Services\OutsourceSessionExpiry;
use App\Enums\AttendanceSessionStatus;
use App\Models\AttendanceSession;
use App\Models\Outsource;
use App\Models\WorkLocation;
use App\Support\AttendanceDateTime;
use Carbon\CarbonImmutable;

class ResolveOutsourceOpenAttendance
{
    public function __construct(
        protected OutsourceSessionExpiry $sessionExpiry,
    ) {}

    /**
     * @return array{
     *   attendance_id: int,
     *   status: string,
     *   attendance_date: string,
     *   check_in_at: string|null,
     *   check_out_at: string|null,
     *   duration_minutes: int|null
     * }|null
     */
    public function execute(int $outsourceId): ?array
    {
        $open = AttendanceSession::query()
            ->where('status', AttendanceSessionStatus::Open->value)
            ->whereHas('attendanceRecord', function ($query) use ($outsourceId) {
                $query->where('outsource_id', $outsourceId)
                    ->where('attendable_type', 'outsource');
            })
            ->with('attendanceRecord')
            ->orderByDesc('check_in_at')
            ->first();

        if ($open === null || $open->attendanceRecord === null) {
            return null;
        }

        if ($this->sessionExpiry->expireIfPastLimit($open, CarbonImmutable::now('UTC'))) {
            return null;
        }

        $record = $open->attendanceRecord;

        return [
            'attendance_id' => $record->id,
            'status' => (string) $record->status,
            'attendance_date' => $record->attendance_date?->toDateString() ?? '',
            'check_in_at' => AttendanceDateTime::toApi($open->check_in_at),
            'check_out_at' => AttendanceDateTime::toApi($open->check_out_at),
            'duration_minutes' => $open->duration_minutes !== null ? (int) $open->duration_minutes : null,
        ];
    }

    /**
     * @return array{
     *   status: string,
     *   expires_at: string,
     *   outsource: array{id: int, name: string, outsource_code: string|null},
     *   store: array{id: int, name: string, city_id: int|null},
     *   attendance: array<string, mixed>|null
     * }
     */
    public function buildSessionPayload(
        string $status,
        string $expiresAt,
        Outsource $outsource,
        WorkLocation $store,
        ?array $attendance,
    ): array {
        return [
            'status' => $status,
            'expires_at' => $expiresAt,
            'outsource' => [
                'id' => $outsource->id,
                'name' => $outsource->name,
                'outsource_code' => $outsource->outsource_code,
            ],
            'store' => [
                'id' => $store->id,
                'name' => $store->name,
                'city_id' => $store->city_id,
            ],
            'attendance' => $attendance,
        ];
    }
}
