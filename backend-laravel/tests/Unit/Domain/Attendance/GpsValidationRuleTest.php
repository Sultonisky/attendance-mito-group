<?php

namespace Tests\Unit\Domain\Attendance;

use App\Domain\Attendance\Exceptions\InvalidLocationException;
use App\Domain\Attendance\Rules\GpsValidationRule;
use PHPUnit\Framework\TestCase;

class GpsValidationRuleTest extends TestCase
{
    private GpsValidationRule $rule;

    protected function setUp(): void
    {
        parent::setUp();
        $this->rule = new GpsValidationRule;
    }

    public function test_valid_coordinates_pass(): void
    {
        $this->rule->validate(-6.2, 106.8, 12.5);
        $this->assertTrue(true);
    }

    public function test_null_accuracy_is_accepted(): void
    {
        $this->rule->validate(-6.2, 106.8, null);
        $this->assertTrue(true);
    }

    public function test_boundary_latitude_is_accepted(): void
    {
        $this->rule->validate(-90, 106.8, 12.5);
        $this->rule->validate(90, 106.8, 12.5);
        $this->assertTrue(true);
    }

    public function test_boundary_longitude_is_accepted(): void
    {
        $this->rule->validate(-6.2, -180, 12.5);
        $this->rule->validate(-6.2, 180, 12.5);
        $this->assertTrue(true);
    }

    public function test_zero_accuracy_is_accepted(): void
    {
        $this->rule->validate(-6.2, 106.8, 0);
        $this->assertTrue(true);
    }

    public function test_accuracy_above_configured_limit_is_rejected(): void
    {
        $this->expectException(InvalidLocationException::class);
        $this->rule->validate(-6.2, 106.8, 212, 100);
    }

    public function test_nan_latitude_throws_exception(): void
    {
        $this->expectException(InvalidLocationException::class);
        $this->rule->validate(NAN, 106.8, 12.5);
    }

    public function test_nan_longitude_throws_exception(): void
    {
        $this->expectException(InvalidLocationException::class);
        $this->rule->validate(-6.2, NAN, 12.5);
    }

    public function test_positive_infinity_latitude_throws_exception(): void
    {
        $this->expectException(InvalidLocationException::class);
        $this->rule->validate(INF, 106.8, 12.5);
    }

    public function test_negative_infinity_longitude_throws_exception(): void
    {
        $this->expectException(InvalidLocationException::class);
        $this->rule->validate(-6.2, -INF, 12.5);
    }

    public function test_nan_accuracy_throws_exception(): void
    {
        $this->expectException(InvalidLocationException::class);
        $this->rule->validate(-6.2, 106.8, NAN);
    }

    public function test_infinity_accuracy_throws_exception(): void
    {
        $this->expectException(InvalidLocationException::class);
        $this->rule->validate(-6.2, 106.8, INF);
    }
}
