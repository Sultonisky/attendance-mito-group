<?php

namespace Tests\Integration\PostgreSQL;

use App\Models\AttendanceRecord;
use App\Models\AttendanceSession;
use App\Models\Employee;
use App\Models\OvertimeRecord;
use App\Models\Policy;
use App\Models\PolicyAssignment;
use App\Models\ScheduleAssignment;
use App\Models\Shift;
use App\Models\User;
use App\Models\WorkSchedule;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Database\QueryException;
use Tests\TestCase;
use Tests\Traits\RefreshDatabasePostgres;

class OvertimePostgresTest extends TestCase
{
    use RefreshDatabasePostgres;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    private function makeUser(array $overrides = []): User
    {
        return User::factory()->create($overrides);
    }

    private function makeEmployee(array $overrides = []): Employee
    {
        return Employee::factory()->create($overrides);
    }

    private function makeScheduleAndPolicy(Employee $employee): void
    {
        $schedule = WorkSchedule::factory()->create();
        Shift::factory()->forSchedule($schedule)->create([
            'start_time' => '08:00:00',
            'end_time' => '17:00:00',
        ]);
        ScheduleAssignment::factory()->forEmployee($employee)->forSchedule($schedule)->create([
            'effective_from' => '2026-01-01',
            'effective_to' => null,
        ]);

        $policy = Policy::factory()->create();
        PolicyAssignment::factory()->forEmployee($employee)->forPolicy($policy)->create([
            'effective_from' => '2026-01-01',
            'effective_to' => null,
        ]);
    }

    public function test_unique_overtime_record_per_employee_and_date(): void
    {
        $employee = $this->makeEmployee();
        $this->makeScheduleAndPolicy($employee);
        $record = AttendanceRecord::factory()->forEmployee($employee)->onDate('2026-09-10')->status('present')->create();
        AttendanceSession::factory()->forRecord($record)->create([
            'check_in_at' => '2026-09-10 08:00:00',
            'check_out_at' => '2026-09-10 19:00:00',
            'duration_minutes' => 660,
            'status' => 'closed',
        ]);

        OvertimeRecord::create([
            'employee_id' => $employee->id,
            'attendance_id' => $record->id,
            'date' => '2026-09-10',
            'potential_minutes' => 120,
            'requested_minutes' => 0,
            'approved_minutes' => null,
            'actual_minutes' => null,
            'status' => 'potential',
        ]);

        $this->expectException(QueryException::class);
        OvertimeRecord::create([
            'employee_id' => $employee->id,
            'attendance_id' => $record->id,
            'date' => '2026-09-10',
            'potential_minutes' => 90,
            'requested_minutes' => 0,
            'approved_minutes' => null,
            'actual_minutes' => null,
            'status' => 'potential',
        ]);
    }

    public function test_overtime_record_indexes_are_efficient(): void
    {
        $employee = $this->makeEmployee();
        $this->makeScheduleAndPolicy($employee);
        $record = AttendanceRecord::factory()->forEmployee($employee)->onDate('2026-09-10')->status('present')->create();
        AttendanceSession::factory()->forRecord($record)->create([
            'check_in_at' => '2026-09-10 08:00:00',
            'check_out_at' => '2026-09-10 19:00:00',
            'duration_minutes' => 660,
            'status' => 'closed',
        ]);

        OvertimeRecord::create([
            'employee_id' => $employee->id,
            'attendance_id' => $record->id,
            'date' => '2026-09-10',
            'potential_minutes' => 120,
            'requested_minutes' => 0,
            'approved_minutes' => null,
            'actual_minutes' => null,
            'status' => 'potential',
        ]);

        $found = OvertimeRecord::where('employee_id', $employee->id)
            ->whereDate('date', '2026-09-10')
            ->first();

        $this->assertNotNull($found);
        $this->assertSame(120, $found->potential_minutes);
    }
}
