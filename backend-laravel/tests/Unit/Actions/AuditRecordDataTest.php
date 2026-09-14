<?php

namespace Tests\Unit\Actions;

use App\DTOs\Audit\AuditRecordData;
use App\Models\AttendanceRecord;
use PHPUnit\Framework\TestCase;

class AuditRecordDataTest extends TestCase
{
    public function test_audit_record_data_holds_expected_values(): void
    {
        $dto = new AuditRecordData(
            actorId: 1,
            action: 'attendance.created',
            auditableType: AttendanceRecord::class,
            auditableId: 10,
            oldValues: [],
            newValues: ['status' => 'incomplete'],
            ipAddress: '127.0.0.1',
            userAgent: 'phpunit',
            metadata: ['request_id' => 'abc'],
        );

        $this->assertSame(1, $dto->actorId);
        $this->assertSame('attendance.created', $dto->action);
        $this->assertSame(AttendanceRecord::class, $dto->auditableType);
        $this->assertSame(10, $dto->auditableId);
        $this->assertSame(['status' => 'incomplete'], $dto->newValues);
        $this->assertSame('127.0.0.1', $dto->ipAddress);
        $this->assertSame(['request_id' => 'abc'], $dto->metadata);
    }

    public function test_audit_record_data_allows_nullable_relations(): void
    {
        $dto = new AuditRecordData(
            actorId: null,
            action: 'system.maintenance',
            auditableType: null,
            auditableId: null,
            oldValues: [],
            newValues: [],
            ipAddress: null,
            userAgent: null,
            metadata: [],
        );

        $this->assertNull($dto->actorId);
        $this->assertNull($dto->auditableType);
        $this->assertNull($dto->ipAddress);
    }
}
