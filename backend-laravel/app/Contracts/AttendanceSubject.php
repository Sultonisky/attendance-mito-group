<?php

namespace App\Contracts;

use Carbon\CarbonImmutable;

interface AttendanceSubject
{
    public function getId(): int;

    public function getEndDate(): ?CarbonImmutable;

    public function isAttendanceActive(): bool;
}
