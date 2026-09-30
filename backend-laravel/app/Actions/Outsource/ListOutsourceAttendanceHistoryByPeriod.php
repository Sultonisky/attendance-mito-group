<?php

namespace App\Actions\Outsource;

use App\Actions\Action;
use App\Enums\AttendanceEventType;
use App\Enums\AttendanceSessionStatus;
use App\Enums\AttendanceStatus;
use App\Models\AttendanceEvent;
use App\Models\AttendanceRecord;
use App\Models\AttendanceSession;
use App\Support\AttendanceDateTime;
use App\Support\OutsourceAttendancePeriod;
use Carbon\CarbonImmutable;

class ListOutsourceAttendanceHistoryByPeriod implements Action
{
    public const STATUS_ABSENT = 'absent';

    public const STATUS_PENDING = 'pending';

    /**
     * Own attendance for one monthly period (start-day cutoff), one item per day.
     *
     * Every day from the period start up to today (or the period end for past periods)
     * is returned, newest first: days with a clock-in carry their sessions, days
     * without one are "absent" (today without a clock-in yet is "pending"). The
     * monthly recap's counted-days quota does not limit this list.
     *
     * A session belongs to its record's attendance_date (the clock-in business day),
     * so a cross-midnight session (e.g. IN 24 Sep 22:00, OUT 25 Sep 06:00) stays whole
     * in the period of its clock-in day and is never split.
     *
     * @return array{
     *   period: array<string, mixed>,
     *   summary: array<string, int|null>,
     *   items: list<array<string, mixed>>
     * }
     */
    public function execute(int $outsourceId, OutsourceAttendancePeriod $period): array
    {
        $current = OutsourceAttendancePeriod::current();
        $today = CarbonImmutable::now(AttendanceDateTime::timezone())->startOfDay();
        $lastDay = $period->endDate->lessThan($today) ? $period->endDate : $today;

        $recordsByDate = AttendanceRecord::query()
            ->where('outsource_id', $outsourceId)
            ->where('attendable_type', 'outsource')
            ->where('attendance_date', '>=', $period->startDate->toDateString())
            ->where('attendance_date', '<', $lastDay->addDay()->toDateString())
            ->whereHas('sessions', fn ($query) => $query->whereNotNull('check_in_at'))
            ->with([
                'sessions' => fn ($query) => $query->orderBy('check_in_at'),
                'sessions.events' => fn ($query) => $query
                    ->select(['id', 'attendance_session_id', 'event_type', 'work_location_pin_id', 'occurred_at'])
                    ->orderBy('occurred_at')
                    ->with(['workLocationPin' => fn ($pin) => $pin->withTrashed()->select(['id', 'name', 'address'])]),
            ])
            ->get()
            ->keyBy(fn (AttendanceRecord $record) => $record->attendance_date?->toDateString());

        $items = [];
        for ($day = $lastDay; $day->greaterThanOrEqualTo($period->startDate); $day = $day->subDay()) {
            $date = $day->toDateString();
            $record = $recordsByDate->get($date);

            $items[] = $record !== null
                ? $this->mapRecord($record)
                : $this->missingDay($date, $day->equalTo($today) ? self::STATUS_PENDING : self::STATUS_ABSENT);
        }

        return [
            'period' => [
                'key' => $period->key,
                'start_date' => $period->startDate->toDateString(),
                'end_date' => $period->endDate->toDateString(),
                'is_current' => $period->key === $current->key,
                'previous_key' => $period->previous()->isBefore(OutsourceAttendancePeriod::first())
                    ? null
                    : $period->previous()->key,
                'next_key' => $period->next()->isAfter($current) ? null : $period->next()->key,
            ],
            'summary' => $this->summarize($items),
            'items' => $items,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function mapRecord(AttendanceRecord $record): array
    {
        $sessions = $record->sessions;
        $duration = (int) $sessions->sum(fn (AttendanceSession $session) => (int) ($session->duration_minutes ?? 0));
        $hasOpen = $sessions->contains(
            fn (AttendanceSession $session) => $session->status === AttendanceSessionStatus::Open->value
        );

        $attendanceDate = $record->attendance_date?->toDateString() ?? '';
        $lastOut = $sessions->pluck('check_out_at')->filter()->max();
        $lastOutDate = $lastOut !== null ? AttendanceDateTime::toBusinessDate($lastOut) : null;

        return [
            'attendance_id' => (int) $record->id,
            'attendance_date' => $attendanceDate,
            'status' => (string) $record->status,
            'attended' => true,
            'has_open_session' => $hasOpen,
            'check_in_at' => AttendanceDateTime::toApi($sessions->min('check_in_at')),
            'check_out_at' => AttendanceDateTime::toApi($lastOut),
            'check_out_date' => $lastOutDate,
            'check_out_day_offset' => $this->dayOffset($attendanceDate, $lastOutDate),
            'duration_minutes' => $duration > 0 ? $duration : null,
            'session_count' => $sessions->count(),
            'sessions' => $sessions->map(fn (AttendanceSession $session): array => $this->mapSession($session))->values()->all(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function missingDay(string $date, string $status): array
    {
        return [
            'attendance_id' => null,
            'attendance_date' => $date,
            'status' => $status,
            'attended' => false,
            'has_open_session' => false,
            'check_in_at' => null,
            'check_out_at' => null,
            'check_out_date' => null,
            'check_out_day_offset' => null,
            'duration_minutes' => null,
            'session_count' => 0,
            'sessions' => [],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function mapSession(AttendanceSession $session): array
    {
        $inDate = $session->check_in_at !== null ? AttendanceDateTime::toBusinessDate($session->check_in_at) : null;
        $outDate = $session->check_out_at !== null ? AttendanceDateTime::toBusinessDate($session->check_out_at) : null;
        $offset = $this->dayOffset($inDate, $outDate);

        return [
            'status' => (string) $session->status,
            'check_in_at' => AttendanceDateTime::toApi($session->check_in_at),
            'check_out_at' => AttendanceDateTime::toApi($session->check_out_at),
            'check_in_date' => $inDate,
            'check_out_date' => $outDate,
            'crosses_midnight' => $offset !== null && $offset > 0,
            'check_out_day_offset' => $offset,
            'duration_minutes' => $session->duration_minutes !== null ? (int) $session->duration_minutes : null,
            'check_in_location' => $this->location($session, AttendanceEventType::CheckIn),
            'check_out_location' => $this->location($session, AttendanceEventType::CheckOut),
        ];
    }

    /**
     * Whole business days between two YYYY-MM-DD dates (attendance timezone), or null.
     */
    private function dayOffset(?string $from, ?string $to): ?int
    {
        if ($from === null || $from === '' || $to === null) {
            return null;
        }

        return (int) CarbonImmutable::parse($from)->diffInDays(CarbonImmutable::parse($to), false);
    }

    /**
     * Pin used on the event (no raw GPS coordinates). Latest event of the type wins.
     *
     * @return array{pin_name: string|null, pin_address: string|null}|null
     */
    private function location(AttendanceSession $session, AttendanceEventType $type): ?array
    {
        /** @var AttendanceEvent|null $event */
        $event = $session->events->where('event_type', $type->value)->last();
        if ($event === null) {
            return null;
        }

        return [
            'pin_name' => $event->workLocationPin?->name,
            'pin_address' => $event->workLocationPin?->address,
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $items
     * @return array<string, int|null>
     */
    private function summarize(array $items): array
    {
        $attended = 0;
        $absent = 0;
        $complete = 0;
        $incomplete = 0;
        $totalMinutes = 0;
        $totalSessions = 0;
        $crossMidnight = 0;

        foreach ($items as $item) {
            if (! $item['attended']) {
                if ($item['status'] === self::STATUS_ABSENT) {
                    $absent++;
                }

                continue;
            }

            $attended++;
            $totalSessions += $item['session_count'];
            $totalMinutes += (int) ($item['duration_minutes'] ?? 0);

            if ($item['status'] === AttendanceStatus::Incomplete->value || $item['has_open_session']) {
                $incomplete++;
            } else {
                $complete++;
            }

            if (collect($item['sessions'])->contains('crosses_midnight', true)) {
                $crossMidnight++;
            }
        }

        return [
            'days_listed' => count($items),
            'days_attended' => $attended,
            'days_absent' => $absent,
            'days_complete' => $complete,
            'days_incomplete' => $incomplete,
            'days_cross_midnight' => $crossMidnight,
            'total_sessions' => $totalSessions,
            'total_duration_minutes' => $totalMinutes,
            'average_duration_minutes' => $attended > 0 ? intdiv($totalMinutes, $attended) : null,
        ];
    }
}
