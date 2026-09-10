<?php

namespace Tests\Feature;

use App\Models\AttendanceRecord;
use App\Models\AuditLog;
use App\Models\Employee;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuditSchemaTest extends TestCase
{
    use RefreshDatabase;

    /**
     * An audit entry can reference an actor (user).
     */
    public function test_audit_log_references_actor(): void
    {
        $actor = User::factory()->create();

        $log = AuditLog::create([
            'actor_id' => $actor->id,
            'action' => 'employee.update',
        ]);

        $this->assertSame($actor->id, $log->actor()->first()->id);
    }

    /**
     * An audit entry can polymorphically reference an entity.
     */
    public function test_audit_log_references_polymorphic_entity(): void
    {
        $employee = Employee::factory()->create();
        $record = AttendanceRecord::create([
            'employee_id' => $employee->id,
            'attendance_date' => '2026-09-10',
            'status' => 'incomplete',
        ]);

        AuditLog::create([
            'action' => 'attendance.create',
            'auditable_type' => AttendanceRecord::class,
            'auditable_id' => $record->id,
            'old_values' => [],
            'new_values' => ['status' => 'incomplete'],
            'ip_address' => '127.0.0.1',
        ]);

        $log = AuditLog::first();

        $this->assertSame($record->id, $log->auditable()->first()->id);
        $this->assertSame('attendance.create', $log->action);
        $this->assertSame('127.0.0.1', $log->ip_address);
    }

    /**
     * Audit created_at is populated by the database default.
     */
    public function test_audit_log_created_at_has_database_default(): void
    {
        AuditLog::create([
            'action' => 'employee.update',
        ]);

        $this->assertNotNull(AuditLog::first()->created_at);
    }

    /**
     * The audit actor relationship is nullable (system/guest actions).
     */
    public function test_audit_log_actor_is_nullable(): void
    {
        $log = AuditLog::create([
            'action' => 'system.maintenance',
        ]);

        $this->assertNull($log->actor_id);
        $this->assertNull($log->actor()->first());
    }

    /**
     * Audit old/new snapshots round-trip through JSONB.
     */
    public function test_audit_log_jsonb_snapshots_round_trip(): void
    {
        AuditLog::create([
            'action' => 'employee.update',
            'old_values' => ['full_name' => 'Old Name'],
            'new_values' => ['full_name' => 'New Name'],
        ]);

        $log = AuditLog::first();

        $this->assertSame('Old Name', $log->old_values['full_name']);
        $this->assertSame('New Name', $log->new_values['full_name']);
    }
}
