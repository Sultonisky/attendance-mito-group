<?php

namespace App\Domain\Attendance\Rules;

use App\Domain\Attendance\Exceptions\InvalidLocationException;

/**
 * Validates GPS coordinates and accuracy.
 */
class GpsValidationRule
{
    public function validate(float $latitude, float $longitude, ?float $accuracyMeters): void
    {
        if ($latitude < -90 || $latitude > 90) {
            throw new InvalidLocationException('Invalid latitude.');
        }

        if ($longitude < -180 || $longitude > 180) {
            throw new InvalidLocationException('Invalid longitude.');
        }

        if ($accuracyMeters !== null && $accuracyMeters < 0) {
            throw new InvalidLocationException('Invalid accuracy.');
        }
    }
}
