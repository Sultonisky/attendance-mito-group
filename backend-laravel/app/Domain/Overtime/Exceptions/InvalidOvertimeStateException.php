<?php

namespace App\Domain\Overtime\Exceptions;

use App\Exceptions\Domain\DomainException;

/**
 * Thrown when an overtime state transition is not permitted.
 */
class InvalidOvertimeStateException extends DomainException {}
