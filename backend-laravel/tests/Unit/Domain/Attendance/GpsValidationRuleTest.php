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

    public function test_invalid_latitude_throws_exception(): void
    {
        $this->expectException(InvalidLocationException::class);
        $this->rule->validate(100, 106.8, 12.5);
    }

    public function test_invalid_longitude_throws_exception(): void
    {
        $this->expectException(InvalidLocationException::class);
        $this->rule->validate(-6.2, 200, 12.5);
    }

    public function test_negative_accuracy_throws_exception(): void
    {
        $this->expectException(InvalidLocationException::class);
        $this->rule->validate(-6.2, 106.8, -5);
    }
}
