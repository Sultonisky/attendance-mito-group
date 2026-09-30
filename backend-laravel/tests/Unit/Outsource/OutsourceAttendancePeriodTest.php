<?php

namespace Tests\Unit\Outsource;

use App\Support\OutsourceAttendancePeriod;
use Carbon\CarbonImmutable;
use InvalidArgumentException;
use Tests\TestCase;

class OutsourceAttendancePeriodTest extends TestCase
{
    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    public function test_key_maps_to_25th_through_24th_of_end_month(): void
    {
        $period = OutsourceAttendancePeriod::fromKey('2026-09');

        $this->assertSame('2026-08-25', $period->startDate->toDateString());
        $this->assertSame('2026-09-24', $period->endDate->toDateString());
    }

    public function test_january_period_starts_in_previous_year(): void
    {
        $period = OutsourceAttendancePeriod::fromKey('2027-01');

        $this->assertSame('2026-12-25', $period->startDate->toDateString());
        $this->assertSame('2027-01-24', $period->endDate->toDateString());
    }

    public function test_containing_switches_on_start_day(): void
    {
        $tz = 'Asia/Jakarta';

        $this->assertSame('2026-09', OutsourceAttendancePeriod::containing(CarbonImmutable::parse('2026-09-24 23:59', $tz))->key);
        $this->assertSame('2026-10', OutsourceAttendancePeriod::containing(CarbonImmutable::parse('2026-09-25 00:00', $tz))->key);
        $this->assertSame('2027-01', OutsourceAttendancePeriod::containing(CarbonImmutable::parse('2026-12-31 10:00', $tz))->key);
    }

    public function test_containing_uses_attendance_timezone(): void
    {
        // 2026-09-24 18:00 UTC is already 2026-09-25 01:00 in Asia/Jakarta.
        $period = OutsourceAttendancePeriod::containing(CarbonImmutable::parse('2026-09-24 18:00', 'UTC'));

        $this->assertSame('2026-10', $period->key);
    }

    public function test_previous_and_next(): void
    {
        $period = OutsourceAttendancePeriod::fromKey('2026-01');

        $this->assertSame('2025-12', $period->previous()->key);
        $this->assertSame('2026-02', $period->next()->key);
    }

    public function test_first_period_is_25_aug_to_24_sep_2026(): void
    {
        $first = OutsourceAttendancePeriod::first();

        $this->assertSame('2026-09', $first->key);
        $this->assertSame('2026-08-25', $first->startDate->toDateString());
        $this->assertSame('2026-09-24', $first->endDate->toDateString());
        $this->assertTrue(OutsourceAttendancePeriod::fromKey('2026-08')->isBefore($first));
        $this->assertFalse($first->isBefore($first));
    }

    public function test_max_attendance_days_defaults_to_26_and_is_clamped(): void
    {
        $this->assertSame(26, OutsourceAttendancePeriod::maxAttendanceDays());

        config(['attendance.outsource_period_max_attendance_days' => 0]);
        $this->assertSame(1, OutsourceAttendancePeriod::maxAttendanceDays());

        config(['attendance.outsource_period_max_attendance_days' => 40]);
        $this->assertSame(31, OutsourceAttendancePeriod::maxAttendanceDays());
    }

    public function test_start_day_is_configurable(): void
    {
        config(['attendance.outsource_period_start_day' => 21]);

        $period = OutsourceAttendancePeriod::fromKey('2026-03');

        $this->assertSame('2026-02-21', $period->startDate->toDateString());
        $this->assertSame('2026-03-20', $period->endDate->toDateString());
    }

    public function test_rejects_invalid_key(): void
    {
        $this->expectException(InvalidArgumentException::class);

        OutsourceAttendancePeriod::fromKey('2026-13');
    }
}
