<?php

namespace App\Services\Outsource;

use App\Enums\AttendanceEventType;
use App\Models\AttendanceEvent;
use App\Models\AttendanceSession;
use App\Support\AttendanceDateTime;

/**
 * Clock-in / clock-out location summary for the outsource's own attendance screen.
 * Read-only view over attendance_events; never used for attendance decisions.
 */
class OutsourceAttendanceLocationSummary
{
    /**
     * @return array{check_in_location: array<string, mixed>|null, check_out_location: array<string, mixed>|null}
     */
    public function forSession(AttendanceSession $session): array
    {
        $events = AttendanceEvent::query()
            ->with('workLocationPin.workLocation')
            ->where('attendance_session_id', $session->id)
            ->whereIn('event_type', [
                AttendanceEventType::CheckIn->value,
                AttendanceEventType::CheckOut->value,
            ])
            ->orderBy('occurred_at')
            ->get();

        $checkIn = $events->firstWhere('event_type', AttendanceEventType::CheckIn->value);
        $checkOut = $events->where('event_type', AttendanceEventType::CheckOut->value)->last();

        return [
            'check_in_location' => $checkIn !== null ? $this->toLocation($checkIn) : null,
            'check_out_location' => $checkOut !== null ? $this->toLocation($checkOut) : null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function toLocation(AttendanceEvent $event): array
    {
        $pin = $event->workLocationPin;
        $workLocation = $pin?->workLocation;

        return [
            'occurred_at' => AttendanceDateTime::toApi($event->occurred_at),
            'pin' => $pin !== null ? [
                'id' => $pin->id,
                'name' => $pin->name,
                'address' => $pin->address,
            ] : null,
            'work_location' => $workLocation !== null ? [
                'id' => $workLocation->id,
                'name' => $workLocation->name,
            ] : null,
        ];
    }
}
