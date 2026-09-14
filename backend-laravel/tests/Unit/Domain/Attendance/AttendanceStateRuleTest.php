<?php

namespace Tests\Unit\Domain\Attendance;

use App\Domain\Attendance\Rules\AttendanceStateRule;
use App\Enums\AttendanceStatus;
use PHPUnit\Framework\TestCase;

class AttendanceStateRuleTest extends TestCase
{
    private AttendanceStateRule $rule;

    protected function setUp(): void
    {
        parent::setUp();
        $this->rule = new AttendanceStateRule;
    }

    public function test_absent_when_no_policy_or_schedule(): void
    {
        $this->assertSame(AttendanceStatus::Absent, $this->rule->determine(false, false, false, false, false));
    }

    public function test_incomplete_when_open_session_exists(): void
    {
        $this->assertSame(AttendanceStatus::Incomplete, $this->rule->determine(true, true, true, false, false));
    }

    public function test_late_when_check_in_is_late(): void
    {
        $this->assertSame(AttendanceStatus::Late, $this->rule->determine(true, true, false, true, false));
    }

    public function test_late_when_early_checkout(): void
    {
        $this->assertSame(AttendanceStatus::Late, $this->rule->determine(true, true, false, false, true));
    }

    public function test_present_when_all_conditions_normal(): void
    {
        $this->assertSame(AttendanceStatus::Present, $this->rule->determine(true, true, false, false, false));
    }
}
