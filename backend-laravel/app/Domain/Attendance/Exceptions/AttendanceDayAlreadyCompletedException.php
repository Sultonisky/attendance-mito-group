<?php

namespace App\Domain\Attendance\Exceptions;

use App\Exceptions\Domain\DomainException;

/**
 * Thrown when an outsource subject already completed (or exhausted) their
 * single allowed attendance session for the clock-in date.
 */
class AttendanceDayAlreadyCompletedException extends DomainException {}
