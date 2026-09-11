<?php

namespace Tests\Unit\Domain\Attendance;

use App\Domain\Attendance\Rules\LateDetectionRule;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\TestCase;

class LateDetectionRuleTest extends TestCase
{
    private LateDetectionRule $rule;

    protected function setUp(): void
    {
        parent::setUp();
        $this->rule = new LateDetectionRule;
    }

    public function test_on_time_is_not_late(): void
    {
        $checkIn = CarbonImmutable::parse('2026-09-11 09:00:00');
        $scheduledStart = CarbonImmutable::parse('2026-09-11 09:00:00');

        $this->assertFalse($this->rule->isLate($checkIn, $scheduledStart));
    }

    public function test_late_check_in_is_detected(): void
    {
        $checkIn = CarbonImmutable::parse('2026-09-11 09:15:00');
        $scheduledStart = CarbonImmutable::parse('2026-09-11 09:00:00');

        $this->assertTrue($this->rule->isLate($checkIn, $scheduledStart));
    }

    public function test_grace_period_absorbs_small_late(): void
    {
        $checkIn = CarbonImmutable::parse('2026-09-11 09:05:00');
        $scheduledStart = CarbonImmutable::parse('2026-09-11 09:00:00');

        $this->assertFalse($this->rule->isLate($checkIn, $scheduledStart, 10));
    }

    public function test_grace_period_does_not_absorb_large_late(): void
    {
        $checkIn = CarbonImmutable::parse('2026-09-11 09:15:00');
        $scheduledStart = CarbonImmutable::parse('2026-09-11 09:00:00');

        $this->assertTrue($this->rule->isLate($checkIn, $scheduledStart, 10));
    }

    public function test_null_scheduled_start_is_not_late(): void
    {
        $checkIn = CarbonImmutable::parse('2026-09-11 09:15:00');

        $this->assertFalse($this->rule->isLate($checkIn, null));
    }
}
