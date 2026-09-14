<?php

namespace App\Domain\Overtime\Exceptions;

use App\Exceptions\Domain\DomainException;

/**
 * Thrown when overtime calculation requires attendance but none exists.
 */
class MissingAttendanceException extends DomainException {}
