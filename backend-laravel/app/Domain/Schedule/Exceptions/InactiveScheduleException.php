<?php

namespace App\Domain\Schedule\Exceptions;

use App\Exceptions\Domain\DomainException;

/**
 * Thrown when the resolved work schedule is not in an active state.
 */
class InactiveScheduleException extends DomainException {}
