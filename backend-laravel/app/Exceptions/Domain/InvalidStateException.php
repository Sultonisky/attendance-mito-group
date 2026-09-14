<?php

namespace App\Exceptions\Domain;

/**
 * Thrown when an operation cannot proceed because the current entity state
 * does not permit the requested transition.
 *
 * Examples:
 * - Attempting to check out an attendance session that is not open.
 * - Attempting to finalize a monthly recap that is not in REVIEW state.
 * - Approving a leave request that has already been cancelled.
 */
class InvalidStateException extends DomainException {}
