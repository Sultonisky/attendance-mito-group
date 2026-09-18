<?php

namespace Tests\Feature\Outsource;

use App\Models\AttendanceRecord;
use App\Models\Employee;
use App\Models\Outsource;
use App\Models\OutsourceStoreAssignment;
use App\Models\Policy;
use App\Models\PolicyAssignment;
use App\Models\ScheduleAssignment;
use App\Models\Shift;
use App\Models\User;
use App\Models\WorkLocation;
use App\Models\WorkSchedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class AttendanceIdentityTest extends TestCase
{
    use RefreshDatabase;

    private function makeUser(array $overrides = []): User
    {
        return User::factory()->create($overrides);
    }

    private function makeEmployee(array $overrides = []): Employee
    {
        return Employee::factory()->create($overrides);
    }

    private function userForEmployee(Employee $employee, array $overrides = []): User
    {
        return User::firstOrCreate(
            ['email' => $employee->email],
            array_merge([
                'name' => $employee->full_name,
                'password' => Hash::make('password'),
            ], $overrides)
        );
    }

    private function makeWorkLocation(): WorkLocation
    {
        return WorkLocation::factory()->create([
            'latitude' => -6.2,
            'longitude' => 106.8,
            'radius_meters' => 1000,
        ]);
    }

    private function makeScheduleAndPolicy(Employee $employee): void
    {
        $schedule = WorkSchedule::factory()->create();
        Shift::factory()->forSchedule($schedule)->create([
            'start_time' => '08:00:00',
            'end_time' => '17:00:00',
        ]);
        ScheduleAssignment::factory()->forEmployee($employee)->forSchedule($schedule)->create([
            'effective_from' => now()->subYear()->toDateString(),
            'effective_to' => null,
        ]);

        $policy = Policy::factory()->create();
        PolicyAssignment::factory()->forEmployee($employee)->forPolicy($policy)->create([
            'effective_from' => now()->subYear()->toDateString(),
            'effective_to' => null,
        ]);
    }

    public function test_employee_attendance_remains_valid(): void
    {
        $employee = $this->makeEmployee();
        $this->makeWorkLocation();
        $this->makeScheduleAndPolicy($employee);
        $user = $this->userForEmployee($employee);

        $response = $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/attendance/check-in', [
                'latitude' => -6.2001,
                'longitude' => 106.8001,
                'accuracy' => 12.5,
                'work_location_id' => WorkLocation::first()->id,
            ]);

        $response->assertStatus(201);
        $this->assertDatabaseHas('attendance_records', [
            'employee_id' => $employee->id,
            'outsource_id' => null,
            'attendable_type' => 'employee',
        ]);
    }

    public function test_outsource_attendance_can_be_created(): void
    {
        $outsource = Outsource::factory()->create(['status' => 'active']);

        $record = AttendanceRecord::create([
            'employee_id' => null,
            'outsource_id' => $outsource->id,
            'attendable_type' => 'outsource',
            'attendance_date' => '2026-09-15',
            'status' => 'present',
        ]);

        $this->assertNotNull($record);
        $this->assertSame('outsource', $record->attendable_type);
        $this->assertSame($outsource->id, $record->outsource_id);
        $this->assertNull($record->employee_id);
    }

    public function test_attendance_record_cannot_have_two_subjects(): void
    {
        if (Schema::getConnection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped('Database CHECK constraint enforcement is PostgreSQL-specific.');
        }

        $employee = Employee::factory()->create();
        $outsource = Outsource::factory()->create();

        $this->expectException(\Illuminate\Database\QueryException::class);

        AttendanceRecord::create([
            'employee_id' => $employee->id,
            'outsource_id' => $outsource->id,
            'attendable_type' => 'employee',
            'attendance_date' => '2026-09-15',
            'status' => 'present',
        ]);
    }

    public function test_attendance_record_cannot_have_no_subject(): void
    {
        if (Schema::getConnection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped('Database CHECK constraint enforcement is PostgreSQL-specific.');
        }

        $this->expectException(\Illuminate\Database\QueryException::class);

        AttendanceRecord::create([
            'employee_id' => null,
            'outsource_id' => null,
            'attendable_type' => 'employee',
            'attendance_date' => '2026-09-15',
            'status' => 'present',
        ]);
    }
}
