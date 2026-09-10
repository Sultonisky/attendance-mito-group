<?php

namespace Tests\Feature;

use App\Enums\AttendanceEventType;
use App\Models\AttendanceEvent;
use App\Models\AttendanceRecord;
use App\Models\AttendanceSession;
use App\Models\Employee;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AttendanceSchemaTest extends TestCase
{
    use RefreshDatabase;

    private function makeEmployee(): Employee
    {
        return Employee::factory()->create();
    }

    /**
     * A duplicate employee/date attendance record is rejected by the database.
     */
    public function test_attendance_employee_date_is_unique(): void
    {
        $employee = $this->makeEmployee();

        AttendanceRecord::create([
            'employee_id' => $employee->id,
            'attendance_date' => '2026-09-10',
            'status' => 'incomplete',
        ]);

        try {
            AttendanceRecord::create([
                'employee_id' => $employee->id,
                'attendance_date' => '2026-09-10',
                'status' => 'present',
            ]);
            $this->fail('Expected UniqueConstraintViolationException was not thrown.');
        } catch (UniqueConstraintViolationException $e) {
            $this->assertTrue(true);
        }
    }

    /**
     * An attendance session belongs to its daily record.
     */
    public function test_session_belongs_to_attendance_record(): void
    {
        $employee = $this->makeEmployee();
        $record = AttendanceRecord::create([
            'employee_id' => $employee->id,
            'attendance_date' => '2026-09-10',
            'status' => 'incomplete',
        ]);
        $session = AttendanceSession::create([
            'attendance_record_id' => $record->id,
            'check_in_at' => '2026-09-10 08:00:00',
            'status' => 'open',
        ]);

        $this->assertSame($record->id, $session->attendanceRecord()->first()->id);
        $this->assertSame(1, $record->sessions()->count());
        $this->assertSame(1, $employee->attendanceRecords()->count());
    }

    /**
     * An attendance event belongs to its record and optional session.
     */
    public function test_event_belongs_to_record_and_session(): void
    {
        $employee = $this->makeEmployee();
        $record = AttendanceRecord::create([
            'employee_id' => $employee->id,
            'attendance_date' => '2026-09-10',
            'status' => 'incomplete',
        ]);
        $session = AttendanceSession::create([
            'attendance_record_id' => $record->id,
            'check_in_at' => '2026-09-10 08:00:00',
            'status' => 'open',
        ]);
        $event = AttendanceEvent::create([
            'employee_id' => $employee->id,
            'attendance_id' => $record->id,
            'attendance_session_id' => $session->id,
            'event_type' => AttendanceEventType::CheckIn->value,
            'occurred_at' => '2026-09-10 08:00:00',
            'latitude' => -6.2,
            'longitude' => 106.8,
        ]);

        $this->assertSame($record->id, $event->attendanceRecord()->first()->id);
        $this->assertSame($session->id, $event->attendanceSession()->first()->id);
        $this->assertSame(1, $record->events()->count());
        $this->assertSame(1, $session->events()->count());
    }

    /**
     * Attendance referencing a nonexistent employee is rejected by the database.
     */
    public function test_attendance_with_nonexistent_employee_is_rejected(): void
    {
        try {
            AttendanceRecord::create([
                'employee_id' => 999999999,
                'attendance_date' => '2026-09-10',
                'status' => 'incomplete',
            ]);
            $this->fail('Expected QueryException was not thrown.');
        } catch (QueryException $e) {
            $this->assertTrue(true);
        }
    }
}
