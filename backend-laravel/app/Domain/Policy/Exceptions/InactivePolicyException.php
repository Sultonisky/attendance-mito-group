<?php

namespace App\Domain\Policy\Exceptions;

use App\Exceptions\Domain\DomainException;

/**
 * Thrown when the resolved policy is not in an active state.
 */
class InactivePolicyException extends DomainException {}
