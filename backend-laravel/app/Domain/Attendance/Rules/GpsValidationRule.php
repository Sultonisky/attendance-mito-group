<?php

namespace App\Domain\Attendance\Rules;

use App\Domain\Attendance\Exceptions\InvalidLocationException;

/**
 * Validates GPS coordinates and accuracy.
 */
class GpsValidationRule
{
    public function validate(float $latitude, float $longitude, ?float $accuracyMeters, ?float $maxAccuracyMeters = null): void
    {
        if (! is_finite($latitude)) {
            throw new InvalidLocationException('Invalid latitude.');
        }

        if (! is_finite($longitude)) {
            throw new InvalidLocationException('Invalid longitude.');
        }

        if ($latitude < -90 || $latitude > 90) {
            throw new InvalidLocationException('Invalid latitude.');
        }

        if ($longitude < -180 || $longitude > 180) {
            throw new InvalidLocationException('Invalid longitude.');
        }

        if ($accuracyMeters !== null && (! is_finite($accuracyMeters) || $accuracyMeters < 0)) {
            throw new InvalidLocationException('Invalid accuracy.');
        }

        if ($accuracyMeters !== null && $maxAccuracyMeters !== null && $accuracyMeters > $maxAccuracyMeters) {
            throw new InvalidLocationException('GPS accuracy is insufficient for attendance.');
        }
    }
}
