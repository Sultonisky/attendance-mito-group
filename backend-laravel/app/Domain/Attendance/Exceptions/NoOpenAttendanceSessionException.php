<?php

namespace App\Domain\Attendance\Exceptions;

use App\Exceptions\Domain\DomainException;

/**
 * Thrown when check-out is requested but no open attendance session exists
 * for the employee.
 */
class NoOpenAttendanceSessionException extends DomainException {}
