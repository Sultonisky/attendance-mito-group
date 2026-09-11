<?php

namespace App\Domain\Attendance\Exceptions;

use App\Exceptions\Domain\DomainException;

/**
 * Thrown when a duplicate attendance request is detected.
 */
class DuplicateAttendanceRequestException extends DomainException {}
