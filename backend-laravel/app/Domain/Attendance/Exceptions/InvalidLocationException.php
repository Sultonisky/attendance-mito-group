<?php

namespace App\Domain\Attendance\Exceptions;

use App\Exceptions\Domain\DomainException;

/**
 * Thrown when GPS coordinates or accuracy are invalid.
 */
class InvalidLocationException extends DomainException {}
