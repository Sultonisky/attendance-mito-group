<?php

namespace App\Domain\Policy\Exceptions;

use App\Exceptions\Domain\DomainException;

/**
 * Thrown when no policy assignment is found for the employee on the given date.
 */
class NoPolicyAssignedException extends DomainException {}
