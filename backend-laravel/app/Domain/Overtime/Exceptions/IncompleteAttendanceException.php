<?php

namespace App\Domain\Overtime\Exceptions;

use App\Exceptions\Domain\DomainException;

/**
 * Thrown when overtime calculation requires closed attendance sessions
 * but the attendance record is incomplete.
 */
class IncompleteAttendanceException extends DomainException {}
