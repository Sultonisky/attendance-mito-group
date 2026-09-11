<?php

namespace App\Domain\Policy\Exceptions;

use App\Exceptions\Domain\DomainException;

/**
 * Thrown when more than one active policy assignment overlaps for the same
 * employee and date.
 */
class AmbiguousPolicyAssignmentException extends DomainException {}
