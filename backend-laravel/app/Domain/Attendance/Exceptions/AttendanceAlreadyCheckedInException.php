<?php

namespace App\Domain\Attendance\Exceptions;

use App\Exceptions\Domain\DomainException;

/**
 * Thrown when an employee attempts to check in but already has an open
 * attendance session for the current work context.
 */
class AttendanceAlreadyCheckedInException extends DomainException {}
