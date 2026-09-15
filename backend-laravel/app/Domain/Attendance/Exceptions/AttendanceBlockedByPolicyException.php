<?php

namespace App\Domain\Attendance\Exceptions;

use App\Exceptions\Domain\DomainException;

/**
 * Thrown when an attendance operation is blocked by policy.
 */
class AttendanceBlockedByPolicyException extends DomainException {}
