<?php

namespace App\Actions\Attendance;

use App\Actions\Audit\RecordAuditAction;
use App\Models\AttendanceEvent;
use App\Models\AttendanceRecord;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Admin void (delete) of an employee daily attendance record.
 */
class VoidEmployeeAttendance
{
    public function __construct(
        private RecordAuditAction $audit,
    ) {}

    public function execute(AttendanceRecord $record, ?User $actor, ?Request $request = null): void
    {
        if ($record->employee_id === null || $record->attendable_type !== 'employee') {
            throw new InvalidArgumentException('Not an employee attendance record.');
        }

        DB::transaction(function () use ($record, $actor, $request): void {
            $snapshot = [
                'attendance_date' => optional($record->attendance_date)?->toDateString() ?? $record->attendance_date,
                'employee_id' => $record->employee_id,
                'status' => $record->status,
            ];

            $sessionIds = $record->sessions()->pluck('id');
            if ($sessionIds->isNotEmpty()) {
                AttendanceEvent::query()->whereIn('attendance_session_id', $sessionIds)->delete();
            }
            AttendanceEvent::query()->where('attendance_id', $record->id)->delete();
            $record->sessions()->delete();
            $record->delete();

            $this->audit->execute(
                $actor?->getKey(),
                'attendance.voided',
                $record,
                $snapshot,
                null,
                $request,
                ['employee_id' => $snapshot['employee_id']],
            );
        });
    }
}
