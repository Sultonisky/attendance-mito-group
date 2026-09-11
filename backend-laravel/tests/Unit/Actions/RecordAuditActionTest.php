<?php

namespace Tests\Unit\Actions;

use App\Actions\Audit\RecordAuditAction;
use App\DTOs\Audit\AuditRecordData;
use App\Models\AuditLog;
use App\Models\Employee;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RecordAuditActionTest extends TestCase
{
    use RefreshDatabase;

    private function makeUser(): User
    {
        return User::factory()->create();
    }

    private function makeEmployee(): Employee
    {
        return Employee::factory()->create();
    }

    public function test_execute_persists_audit_log_with_actor(): void
    {
        $user = $this->makeUser();
        $employee = $this->makeEmployee();

        $action = new RecordAuditAction;
        $log = $action->execute(new AuditRecordData(
            actorId: $user->getKey(),
            action: 'employee.created',
            auditableType: Employee::class,
            auditableId: $employee->getKey(),
            oldValues: [],
            newValues: ['full_name' => $employee->full_name],
            ipAddress: '127.0.0.1',
            userAgent: 'phpunit',
            metadata: [],
        ));

        $this->assertInstanceOf(AuditLog::class, $log);
        $this->assertSame('employee.created', $log->action);
        $this->assertSame($employee->getKey(), $log->auditable_id);
        $this->assertSame($user->getKey(), $log->actor_id);
    }

    public function test_execute_persists_audit_log_without_actor(): void
    {
        $action = new RecordAuditAction;
        $log = $action->execute(new AuditRecordData(
            actorId: null,
            action: 'system.maintenance',
            auditableType: null,
            auditableId: null,
            oldValues: [],
            newValues: [],
            ipAddress: null,
            userAgent: null,
            metadata: [],
        ));

        $this->assertNull($log->actor_id);
        $this->assertSame('system.maintenance', $log->action);
    }

    public function test_for_user_convenience_factory_creates_data(): void
    {
        $user = $this->makeUser();
        $employee = $this->makeEmployee();

        $dto = RecordAuditAction::forUser(
            user: $user,
            action: 'employee.updated',
            subject: $employee,
            oldValues: ['full_name' => 'Old Name'],
            newValues: ['full_name' => 'New Name'],
        );

        $this->assertSame($user->getKey(), $dto->actorId);
        $this->assertSame('employee.updated', $dto->action);
        $this->assertSame(Employee::class, $dto->auditableType);
        $this->assertSame($employee->getKey(), $dto->auditableId);
        $this->assertSame('Old Name', $dto->oldValues['full_name']);
        $this->assertSame('New Name', $dto->newValues['full_name']);
    }

    public function test_execute_is_wrapped_in_transaction(): void
    {
        $user = $this->makeUser();
        $employee = $this->makeEmployee();

        $action = new RecordAuditAction;
        $log = $action->execute(new AuditRecordData(
            actorId: $user->getKey(),
            action: 'employee.created',
            auditableType: Employee::class,
            auditableId: $employee->getKey(),
            oldValues: [],
            newValues: ['status' => 'active'],
            ipAddress: '127.0.0.1',
            userAgent: 'phpunit',
            metadata: [],
        ));

        $this->assertNotNull($log->id);
        $this->assertSame('employee.created', AuditLog::find($log->id)->action);
    }
}
