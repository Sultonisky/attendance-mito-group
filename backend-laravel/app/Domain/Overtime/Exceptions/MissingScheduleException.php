<?php

namespace App\Domain\Overtime\Exceptions;

use App\Exceptions\Domain\DomainException;

/**
 * Thrown when overtime calculation requires a schedule but none is assigned.
 */
class MissingScheduleException extends DomainException {}
