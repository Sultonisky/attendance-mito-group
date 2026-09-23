<?php

namespace App\Actions\Outsource;

use App\Actions\Action;
use App\Models\AttendanceRecord;
use App\Support\AttendanceDateTime;

class ListOutsourceAttendanceHistory implements Action
{
    /**
     * Recent daily attendance history for a logged-in outsource person.
     *
     * @return list<array{
     *   attendance_id: int,
     *   attendance_date: string,
     *   status: string,
     *   check_in_at: string|null,
     *   check_out_at: string|null,
     *   duration_minutes: int|null,
     *   session_count: int
     * }>
     */
    public function execute(int $outsourceId, int $limit = 14): array
    {
        $limit = max(1, min(60, $limit));

        $records = AttendanceRecord::query()
            ->where('outsource_id', $outsourceId)
            ->where('attendable_type', 'outsource')
            ->orderByDesc('attendance_date')
            ->orderByDesc('id')
            ->limit($limit)
            ->with(['sessions' => fn ($query) => $query->orderBy('check_in_at')])
            ->get();

        return $records->map(function (AttendanceRecord $record): array {
            $sessions = $record->sessions;
            $firstIn = $sessions->min('check_in_at');
            $lastOut = $sessions
                ->pluck('check_out_at')
                ->filter()
                ->max();
            $duration = (int) $sessions->sum(fn ($session) => (int) ($session->duration_minutes ?? 0));

            return [
                'attendance_id' => (int) $record->id,
                'attendance_date' => $record->attendance_date?->toDateString() ?? '',
                'status' => (string) $record->status,
                'check_in_at' => AttendanceDateTime::toApi($firstIn),
                'check_out_at' => AttendanceDateTime::toApi($lastOut),
                'duration_minutes' => $duration > 0 ? $duration : null,
                'session_count' => $sessions->count(),
            ];
        })->values()->all();
    }
}
