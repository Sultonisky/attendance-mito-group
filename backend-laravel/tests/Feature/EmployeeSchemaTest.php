<?php

namespace Tests\Feature;

use App\Enums\EmploymentStatus;
use App\Models\Employee;
use App\Models\ScheduleAssignment;
use App\Models\WorkSchedule;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EmployeeSchemaTest extends TestCase
{
    use RefreshDatabase;

    /**
     * The employee business identifier is unique at the database level.
     */
    public function test_employee_code_is_unique_at_database_level(): void
    {
        Employee::factory()->create([
            'employee_code' => 'EMP001',
            'email' => 'first@example.com',
        ]);

        try {
            Employee::factory()->create([
                'employee_code' => 'EMP001',
                'email' => 'second@example.com',
            ]);
            $this->fail('Expected UniqueConstraintViolationException was not thrown.');
        } catch (UniqueConstraintViolationException $e) {
            $this->assertTrue(true);
        }
    }

    /**
     * Employee email is unique when provided.
     */
    public function test_employee_email_is_unique_when_provided(): void
    {
        Employee::factory()->create(['email' => 'shared@example.com']);

        try {
            Employee::factory()->create(['email' => 'shared@example.com']);
            $this->fail('Expected UniqueConstraintViolationException was not thrown.');
        } catch (UniqueConstraintViolationException $e) {
            $this->assertTrue(true);
        }
    }

    /**
     * Employee -> direct superior relationship resolves.
     */
    public function test_direct_superior_relationship_resolves(): void
    {
        $superior = Employee::factory()->create();
        $subordinate = Employee::factory()->create([
            'direct_superior_id' => $superior->id,
        ]);

        $this->assertSame($superior->id, $subordinate->directSuperior->id);
        $this->assertSame($superior->id, $subordinate->directSuperior()->first()->id);
    }

    /**
     * Employee -> indirect superior relationship resolves.
     */
    public function test_indirect_superior_relationship_resolves(): void
    {
        $senior = Employee::factory()->create();
        $subordinate = Employee::factory()->create([
            'indirect_superior_id' => $senior->id,
        ]);

        $this->assertSame($senior->id, $subordinate->indirectSuperior->id);
    }

    /**
     * Employee -> subordinates relationship resolves.
     */
    public function test_subordinates_relationship_resolves(): void
    {
        $superior = Employee::factory()->create();
        $first = Employee::factory()->create(['direct_superior_id' => $superior->id]);
        $second = Employee::factory()->create(['direct_superior_id' => $superior->id]);

        $subordinates = $superior->subordinates()->pluck('id')->all();

        $this->assertTrue(in_array($first->id, $subordinates));
        $this->assertTrue(in_array($second->id, $subordinates));
    }

    /**
     * Employment status enum value is persisted stably.
     */
    public function test_employment_status_is_persisted(): void
    {
        $employee = Employee::factory()->create([
            'employment_status' => EmploymentStatus::Outsource->value,
        ]);

        $this->assertSame(EmploymentStatus::Outsource->value, $employee->fresh()->employment_status);
        $this->assertSame(EmploymentStatus::Outsource->value, $employee->employment_status);
    }

    /**
     * Schedule assignment references a valid employee and schedule.
     */
    public function test_schedule_assignment_relationships(): void
    {
        $employee = Employee::factory()->create();
        $schedule = WorkSchedule::create([
            'code' => 'SCHED-A',
            'name' => 'Standard Office Shift',
        ]);

        $assignment = ScheduleAssignment::create([
            'employee_id' => $employee->id,
            'work_schedule_id' => $schedule->id,
            'effective_from' => '2026-01-01',
        ]);

        $this->assertSame($employee->id, $assignment->employee()->first()->id);
        $this->assertSame($schedule->id, $assignment->workSchedule()->first()->id);
        $this->assertSame(1, $employee->scheduleAssignments()->count());
    }

    /**
     * Soft delete preserves the employee record.
     */
    public function test_soft_delete_preserves_employee_record(): void
    {
        $employee = Employee::factory()->create();

        $employee->delete();

        $this->assertNull(Employee::find($employee->id));
        $this->assertNotNull(Employee::withTrashed()->find($employee->id));
        $this->assertNotNull($employee->deleted_at);
    }
}
