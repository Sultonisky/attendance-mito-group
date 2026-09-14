<?php

namespace Tests\Unit\Domain\Attendance;

use App\Domain\Attendance\Rules\EarlyCheckoutRule;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\TestCase;

class EarlyCheckoutRuleTest extends TestCase
{
    private EarlyCheckoutRule $rule;

    protected function setUp(): void
    {
        parent::setUp();
        $this->rule = new EarlyCheckoutRule;
    }

    public function test_on_time_checkout_is_not_early(): void
    {
        $checkOut = CarbonImmutable::parse('2026-09-11 18:00:00');
        $scheduledEnd = CarbonImmutable::parse('2026-09-11 18:00:00');

        $this->assertFalse($this->rule->isEarlyCheckout($checkOut, $scheduledEnd));
    }

    public function test_early_checkout_is_detected(): void
    {
        $checkOut = CarbonImmutable::parse('2026-09-11 17:30:00');
        $scheduledEnd = CarbonImmutable::parse('2026-09-11 18:00:00');

        $this->assertTrue($this->rule->isEarlyCheckout($checkOut, $scheduledEnd));
    }

    public function test_null_scheduled_end_is_not_early(): void
    {
        $checkOut = CarbonImmutable::parse('2026-09-11 17:30:00');

        $this->assertFalse($this->rule->isEarlyCheckout($checkOut, null));
    }
}
