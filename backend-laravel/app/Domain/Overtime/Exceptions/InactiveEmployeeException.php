<?php

namespace App\Domain\Overtime\Exceptions;

use App\Exceptions\Domain\DomainException;

/**
 * Thrown when overtime calculation requires an active employee but the
 * employee is not currently active.
 */
class InactiveEmployeeException extends DomainException {}
