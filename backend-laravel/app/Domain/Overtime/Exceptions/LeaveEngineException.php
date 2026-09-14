<?php

namespace App\Domain\Overtime\Exceptions;

use App\Exceptions\Domain\DomainException;

/**
 * Thrown when overtime calculation depends on LeaveEngine but it fails.
 *
 * The failure path must not silently fall back to "no leave" and continue
 * with overtime calculation. Approved leave disables overtime, so an
 * unresolved leave check must fail closed.
 */
class LeaveEngineException extends DomainException {}
