<?php

namespace App\Exceptions\Domain;

/**
 * Thrown when a business operation requires an active employee but the
 * employee is not currently active.
 *
 * Examples:
 * - Check-in attempt by an employee with employment_status other than
 *   active/permanent/contract/probation.
 * - Leave request by an employee whose employment has ended.
 */
class InactiveEmployeeException extends DomainException {}
