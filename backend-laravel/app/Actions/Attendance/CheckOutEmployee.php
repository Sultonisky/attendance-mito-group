<?php

namespace App\Actions\Attendance;

use App\Actions\Audit\RecordAuditAction;
use App\Domain\Attendance\DTOs\AttendanceOperationData;
use App\Domain\Attendance\DTOs\AttendanceResultData;
use App\Domain\Attendance\Engines\AttendanceEngine;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Executes a check-out operation within a transaction boundary.
 */
class CheckOutEmployee
{
    public function __construct(
        private AttendanceEngine $engine,
        private RecordAuditAction $recordAuditAction,
    ) {}

    public function execute(User $user, AttendanceOperationData $data): AttendanceResultData
    {
        $employee = $user->employee;

        $result = DB::transaction(function () use ($employee, $data) {
            return $this->engine->checkOut($employee, $data);
        });

        $this->recordAuditAction->execute(RecordAuditAction::forUser(
            user: $user,
            action: 'attendance.checked_out',
            subject: $result->attendanceRecord,
            newValues: [
                'attendance_record_id' => $result->attendanceRecord->id,
                'session_id' => $result->session?->id,
                'duration_minutes' => $result->session?->duration_minutes,
                'status' => $result->attendanceRecord->status,
            ],
        ));

        return $result;
    }
}
