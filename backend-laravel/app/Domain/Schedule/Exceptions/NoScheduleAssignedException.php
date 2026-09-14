<?php

namespace App\Domain\Schedule\Exceptions;

use App\Exceptions\Domain\DomainException;

/**
 * Thrown when no schedule assignment is found for the employee on the given date.
 */
class NoScheduleAssignedException extends DomainException {}
