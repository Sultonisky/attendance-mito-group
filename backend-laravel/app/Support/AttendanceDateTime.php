<?php

namespace App\Support;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * Attendance wall-clock timezone (GMT+7). Storage remains UTC timestamptz;
 * this helper is for business calendar dates and API/display serialization.
 */
final class AttendanceDateTime
{
    public static function timezone(): string
    {
        return (string) config('attendance.timezone', 'Asia/Jakarta');
    }

    public static function toBusinessDate(CarbonInterface|string $at): string
    {
        return CarbonImmutable::parse($at)
            ->timezone(self::timezone())
            ->toDateString();
    }

    public static function startOfBusinessDay(CarbonInterface|string $at): CarbonImmutable
    {
        return CarbonImmutable::parse($at)
            ->timezone(self::timezone())
            ->startOfDay();
    }

    /**
     * Serialize an instant as ISO-8601 in the attendance timezone (e.g. +07:00).
     */
    public static function toApi(mixed $at): ?string
    {
        if ($at === null || $at === '') {
            return null;
        }

        return CarbonImmutable::parse($at)
            ->timezone(self::timezone())
            ->toIso8601String();
    }
}
