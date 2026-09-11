<?php

namespace App\Domain\Schedule\Exceptions;

use App\Exceptions\Domain\DomainException;

/**
 * Thrown when more than one active schedule assignment overlaps for the same
 * employee and date.
 */
class AmbiguousScheduleAssignmentException extends DomainException {}
