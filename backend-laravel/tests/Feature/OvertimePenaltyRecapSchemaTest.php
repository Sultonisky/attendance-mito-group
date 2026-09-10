<?php

namespace Tests\Feature;

use App\Models\AttendanceRecord;
use App\Models\Employee;
use App\Models\MonthlyRecap;
use App\Models\MonthlyRecapDetail;
use App\Models\OvertimeRecord;
use App\Models\OvertimeRequest;
use App\Models\PenaltyRecord;
use App\Models\PenaltyRule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OvertimePenaltyRecapSchemaTest extends TestCase
{
    use RefreshDatabase;

    private function makeEmployee(): Employee
    {
        return Employee::factory()->create();
    }

    private function makeAttendance(string $date = '2026-09-10'): AttendanceRecord
    {
        return AttendanceRecord::create([
            'employee_id' => $this->makeEmployee()->id,
            'attendance_date' => $date,
            'status' => 'incomplete',
        ]);
    }

    /**
     * An overtime request belongs to its employee and attendance record.
     */
    public function test_overtime_request_belongs_to_employee_and_attendance(): void
    {
        $record = $this->makeAttendance();

        $request = OvertimeRequest::create([
            'employee_id' => $record->employee_id,
            'attendance_id' => $record->id,
            'date' => '2026-09-10',
            'requested_minutes' => 90,
            'status' => 'pending',
        ]);

        $this->assertSame($record->employee()->first()->id, $request->employee()->first()->id);
        $this->assertSame($record->id, $request->attendanceRecord()->first()->id);
    }

    /**
     * Overtime records keep the detected/approved/actual minute concepts
     * distinct.
     */
    public function test_overtime_record_keeps_minute_concepts_distinct(): void
    {
        $record = $this->makeAttendance();

        $overtime = OvertimeRecord::create([
            'employee_id' => $record->employee_id,
            'attendance_id' => $record->id,
            'date' => '2026-09-10',
            'potential_minutes' => 100,
            'requested_minutes' => 80,
            'approved_minutes' => 80,
            'actual_minutes' => 75,
            'status' => 'approved',
        ]);

        $this->assertSame(100, $overtime->potential_minutes);
        $this->assertSame(80, $overtime->approved_minutes);
        $this->assertSame(75, $overtime->actual_minutes);
        $this->assertSame('approved', $overtime->status);
    }

    /**
     * A penalty record belongs to its employee and rule, preserving original
     * vs final points.
     */
    public function test_penalty_record_belongs_to_employee_and_rule(): void
    {
        $employee = $this->makeEmployee();
        $rule = PenaltyRule::create([
            'code' => 'late_arrival',
            'name' => 'Late Arrival',
            'points' => 2,
        ]);

        $penalty = PenaltyRecord::create([
            'employee_id' => $employee->id,
            'penalty_rule_id' => $rule->id,
            'original_points' => 2,
            'final_points' => 1,
            'reason' => 'First offense adjusted',
            'status' => 'adjusted',
            'occurred_at' => '2026-09-10 09:15:00',
        ]);

        $this->assertSame($employee->id, $penalty->employee()->first()->id);
        $this->assertSame($rule->id, $penalty->penaltyRule()->first()->id);
        $this->assertSame(2.0, (float) $penalty->original_points);
        $this->assertSame(1.0, (float) $penalty->final_points);
        $this->assertSame(1, $employee->penaltyRecords()->count());
    }

    /**
     * A monthly recap belongs to its employee and owns detail lines.
     */
    public function test_monthly_recap_belongs_to_employee_and_details(): void
    {
        $employee = $this->makeEmployee();

        $recap = MonthlyRecap::create([
            'employee_id' => $employee->id,
            'period' => '2026-09',
            'status' => 'draft',
        ]);
        MonthlyRecapDetail::create([
            'monthly_recap_id' => $recap->id,
            'detail_type' => 'attendance',
            'category' => 'present_days',
            'quantity' => 20,
        ]);
        MonthlyRecapDetail::create([
            'monthly_recap_id' => $recap->id,
            'detail_type' => 'penalty',
            'category' => 'points',
            'value' => 3,
        ]);

        $this->assertSame($employee->id, $recap->employee()->first()->id);
        $this->assertSame(2, $recap->details()->count());
        $this->assertSame('attendance', $recap->details()->first()->detail_type);
    }

    /**
     * The documented recap lifecycle statuses are persisted stably.
     */
    public function test_monthly_recap_status_lifecycle(): void
    {
        $employee = $this->makeEmployee();

        $recap = MonthlyRecap::create([
            'employee_id' => $employee->id,
            'period' => '2026-09',
            'status' => 'draft',
        ]);
        $recap->update(['status' => 'finalized']);

        $this->assertSame('finalized', $recap->fresh()->status);
    }
}
