<?php

namespace App\Domain\Attendance\Services;

use App\Domain\Attendance\DTOs\AttendanceOperationData;
use App\Domain\Attendance\DTOs\AttendanceResultData;
use App\Enums\AttendanceEventType;
use App\Models\AttendanceEvent;
use App\Models\AttendanceSession;
use App\Models\WorkLocation;
use App\Models\WorkLocationPin;
use Carbon\CarbonInterface;

/**
 * Point-in-time clock-in / clock-out location snapshots for audit metadata.
 *
 * Snapshots are written into the audit log so the evidence survives later
 * changes (pin renames, voided attendance deleting its events).
 */
class AttendanceAuditLocation
{
    /**
     * Snapshot of the location used by the operation that just succeeded.
     *
     * @return array<string, mixed>
     */
    public function fromOperation(AttendanceOperationData $data, AttendanceResultData $result): array
    {
        return $this->snapshot(
            occurredAt: $data->occurredAt,
            workLocation: $result->workLocation,
            pin: $result->pin,
            gps: $data->toAuditGps($result->geofence),
        );
    }

    /**
     * Snapshot of the clock-in location recorded for a session (used on check-out).
     *
     * @return array<string, mixed>|null
     */
    public function clockInForSession(AttendanceSession $session): ?array
    {
        $event = AttendanceEvent::query()
            ->with('workLocationPin.workLocation')
            ->where('attendance_session_id', $session->id)
            ->where('event_type', AttendanceEventType::CheckIn->value)
            ->orderBy('occurred_at')
            ->first();

        if ($event === null) {
            return null;
        }

        $pin = $event->workLocationPin;

        return $this->snapshot(
            occurredAt: $event->occurred_at,
            workLocation: $pin?->workLocation,
            pin: $pin,
            gps: $event->latitude !== null && $event->longitude !== null ? [
                'latitude' => (float) $event->latitude,
                'longitude' => (float) $event->longitude,
                'accuracy_meters' => $event->accuracy_meters !== null ? (float) $event->accuracy_meters : null,
                'geofence_passed' => null,
                'distance_meters' => null,
                'geofence_method' => null,
            ] : null,
        );
    }

    /**
     * @param  array<string, mixed>|null  $gps
     * @return array<string, mixed>
     */
    private function snapshot(
        ?CarbonInterface $occurredAt,
        ?WorkLocation $workLocation,
        ?WorkLocationPin $pin,
        ?array $gps,
    ): array {
        return [
            'occurred_at' => $occurredAt?->toIso8601String(),
            'work_location' => $workLocation !== null ? [
                'id' => $workLocation->id,
                'name' => $workLocation->name,
            ] : null,
            'pin' => $pin !== null ? [
                'id' => $pin->id,
                'name' => $pin->name,
                'address' => $pin->address,
            ] : null,
            'gps' => $gps,
        ];
    }
}
