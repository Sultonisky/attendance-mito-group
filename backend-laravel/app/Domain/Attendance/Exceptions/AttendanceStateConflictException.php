<?php

namespace App\Domain\Attendance\Exceptions;

use App\Exceptions\Domain\DomainException;

/**
 * Thrown when an attendance operation conflicts with the current domain
 * state (e.g., duplicate request, concurrent modification).
 */
class AttendanceStateConflictException extends DomainException {}
