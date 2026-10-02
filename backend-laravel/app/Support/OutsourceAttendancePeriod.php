<?php

namespace App\Support;

use Carbon\CarbonImmutable;
use InvalidArgumentException;

/**
 * Monthly outsource attendance period with a mid-month cutoff.
 *
 * With start day 25, period "2026-09" runs 2026-08-25 .. 2026-09-24
 * (keyed by the month in which the period ends).
 */
final class OutsourceAttendancePeriod
{
    private function __construct(
        public readonly string $key,
        public readonly CarbonImmutable $startDate,
        public readonly CarbonImmutable $endDate,
    ) {}

    public static function startDay(): int
    {
        return max(2, min(28, (int) config('attendance.outsource_period_start_day', 25)));
    }

    public static function fromKey(string $key): self
    {
        if (! preg_match('/^(\d{4})-(0[1-9]|1[0-2])$/', $key, $m)) {
            throw new InvalidArgumentException('Period must use YYYY-MM format.');
        }

        $endMonth = CarbonImmutable::create((int) $m[1], (int) $m[2], 1, 0, 0, 0, AttendanceDateTime::timezone());
        $startDay = self::startDay();

        return new self(
            $endMonth->format('Y-m'),
            $endMonth->subMonthNoOverflow()->setDay($startDay)->startOfDay(),
            $endMonth->setDay($startDay - 1)->startOfDay(),
        );
    }

    public static function containing(CarbonImmutable $date): self
    {
        $local = $date->timezone(AttendanceDateTime::timezone());
        $endMonth = $local->day >= self::startDay()
            ? $local->startOfMonth()->addMonthNoOverflow()
            : $local->startOfMonth();

        return self::fromKey($endMonth->format('Y-m'));
    }

    /**
     * Maximum attendance days counted per period; days beyond it are ignored.
     */
    public static function maxAttendanceDays(): int
    {
        return max(1, min(31, (int) config('attendance.outsource_period_max_attendance_days', 26)));
    }

    public static function current(): self
    {
        return self::containing(CarbonImmutable::now(AttendanceDateTime::timezone()));
    }

    /**
     * Earliest period available in outsource history; nothing before it is served.
     */
    public static function first(): self
    {
        return self::fromKey((string) config('attendance.outsource_period_first', '2026-09'));
    }

    public function isBefore(self $other): bool
    {
        return $this->key < $other->key;
    }

    public function previous(): self
    {
        return self::fromKey($this->endMonth()->subMonthNoOverflow()->format('Y-m'));
    }

    public function next(): self
    {
        return self::fromKey($this->endMonth()->addMonthNoOverflow()->format('Y-m'));
    }

    public function isAfter(self $other): bool
    {
        return $this->key > $other->key;
    }

    private function endMonth(): CarbonImmutable
    {
        return $this->endDate->startOfMonth();
    }
}
