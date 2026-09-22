<?php

namespace App\Domain\Attendance\Exceptions;

use App\Exceptions\Domain\DomainException;

/**
 * Thrown when an outsource open session exceeds the max session duration
 * and can no longer be closed with a check-out.
 */
class AttendanceSessionExpiredException extends DomainException {}
