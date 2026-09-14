<?php

namespace App\Domain\Attendance\Exceptions;

use App\Exceptions\Domain\DomainException;

/**
 * Thrown when the employee's location is outside the applicable geofence.
 */
class OutsideGeofenceException extends DomainException {}
