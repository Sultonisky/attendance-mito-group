<?php

namespace App\Actions\Outsource;

use App\Domain\Attendance\Services\OutsourceSessionExpiry;
use App\Enums\AttendanceSessionStatus;
use App\Models\AttendanceSession;
use App\Models\City;
use App\Models\Outsource;
use App\Models\WorkLocation;
use App\Services\Outsource\OutsourceAttendanceLocationSummary;
use App\Services\Outsource\ResolveOutsourceAllowedPins;
use App\Support\AttendanceDateTime;
use Carbon\CarbonImmutable;

class ResolveOutsourceOpenAttendance
{
    public function __construct(
        protected OutsourceSessionExpiry $sessionExpiry,
        protected ResolveOutsourceAllowedPins $resolveAllowedPins,
        protected OutsourceAttendanceLocationSummary $locationSummary,
    ) {}

    /**
     * @return array{
     *   attendance_id: int,
     *   status: string,
     *   session_status: string,
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

        return $this->toSnapshot($open);
    }

    /**
     * Today's finished outsource attendance (closed or expired session).
     * Outsource allows one session per business date, so this means no new clock-in today.
     *
     * @return array<string, mixed>|null
     */
    public function executeCompletedToday(int $outsourceId, ?CarbonImmutable $now = null): ?array
    {
        $today = AttendanceDateTime::toBusinessDate($now ?? CarbonImmutable::now('UTC'));

        $finished = AttendanceSession::query()
            ->whereIn('status', [
                AttendanceSessionStatus::Closed->value,
                AttendanceSessionStatus::Expired->value,
            ])
            ->whereHas('attendanceRecord', function ($query) use ($outsourceId, $today) {
                $query->where('outsource_id', $outsourceId)
                    ->where('attendable_type', 'outsource')
                    ->whereDate('attendance_date', $today);
            })
            ->with('attendanceRecord')
            ->orderByDesc('check_in_at')
            ->first();

        if ($finished === null || $finished->attendanceRecord === null) {
            return null;
        }

        return $this->toSnapshot($finished);
    }

    /**
     * Session UI state: ACTIVE (open IN), COMPLETED (today already done), or READY.
     *
     * @return array{status: 'ACTIVE'|'COMPLETED'|'READY', attendance: array<string, mixed>|null}
     */
    public function resolveState(int $outsourceId): array
    {
        $open = $this->execute($outsourceId);
        if ($open !== null) {
            return ['status' => 'ACTIVE', 'attendance' => $open];
        }

        $completed = $this->executeCompletedToday($outsourceId);
        if ($completed !== null) {
            return ['status' => 'COMPLETED', 'attendance' => $completed];
        }

        return ['status' => 'READY', 'attendance' => null];
    }

    /**
     * @return array<string, mixed>
     */
    private function toSnapshot(AttendanceSession $session): array
    {
        $record = $session->attendanceRecord;

        return [
            'attendance_id' => $record->id,
            'status' => (string) $record->status,
            'session_status' => (string) $session->status,
            'attendance_date' => $record->attendance_date?->toDateString() ?? '',
            'check_in_at' => AttendanceDateTime::toApi($session->check_in_at),
            'check_out_at' => AttendanceDateTime::toApi($session->check_out_at),
            'duration_minutes' => $session->duration_minutes !== null ? (int) $session->duration_minutes : null,
            ...$this->locationSummary->forSession($session),
        ];
    }

    /**
     * @return array{
     *   status: string,
     *   expires_at: string,
     *   outsource: array{id: int, name: string, outsource_code: string|null},
     *   store: array{id: int, name: string, city_id: int|null, city_name: string|null, latitude: float|null, longitude: float|null},
     *   city: array{id: int, name: string}|null,
     *   pins: list<array{id: int, name: string, address: string|null, latitude: float|null, longitude: float|null, radius_meters: float}>,
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
        $pins = [];
        try {
            $resolved = $this->resolveAllowedPins->execute($outsource);
            $pins = $this->resolveAllowedPins->mapPinsForApi($resolved['pins']);
        } catch (\InvalidArgumentException) {
            $pins = [];
        }

        $cityName = null;
        if ($store->city_id) {
            $cityName = City::query()
                ->withoutGlobalScopes()
                ->whereKey($store->city_id)
                ->value('name');
            $cityName = is_string($cityName) ? $cityName : null;
        }

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
                'city_name' => $cityName,
                'latitude' => $store->latitude !== null ? (float) $store->latitude : null,
                'longitude' => $store->longitude !== null ? (float) $store->longitude : null,
            ],
            'city' => $store->city_id ? [
                'id' => (int) $store->city_id,
                'name' => $cityName ?? '',
            ] : null,
            'pins' => $pins,
            'attendance' => $attendance,
        ];
    }
}
