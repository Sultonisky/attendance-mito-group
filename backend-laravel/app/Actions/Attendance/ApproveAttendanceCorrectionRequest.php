<?php

namespace App\Actions\Attendance;

use App\Actions\Audit\RecordAuditAction;
use App\Enums\AttendanceCorrectionStatus;
use App\Enums\AttendanceStatus;
use App\Models\AttendanceCorrectionRequest;
use App\Models\AttendanceRecord;
use App\Models\AttendanceSession;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Approve a pending attendance correction and apply session changes.
 */
class ApproveAttendanceCorrectionRequest
{
    public function __construct(
        private RecordAuditAction $audit,
    ) {}

    public function execute(
        AttendanceCorrectionRequest $correction,
        User $actor,
        ?Request $request = null,
    ): AttendanceCorrectionRequest {
        if ($correction->status !== AttendanceCorrectionStatus::Pending->value) {
            throw new InvalidArgumentException('Only pending correction requests can be approved.');
        }

        return DB::transaction(function () use ($correction, $actor, $request): AttendanceCorrectionRequest {
            $before = $correction->only(['status', 'reviewed_by', 'reviewed_at']);

            $record = $this->resolveRecord($correction);
            $session = $this->applySessionCorrection($correction, $record);
            $this->refreshDailyStatus($record);

            $correction->update([
                'attendance_record_id' => $record->id,
                'attendance_session_id' => $session->id,
                'status' => AttendanceCorrectionStatus::Approved->value,
                'reviewed_by' => $actor->id,
                'reviewed_at' => now(),
                'rejection_reason' => null,
            ]);

            $this->audit->execute(
                $actor->getKey(),
                'attendance.correction_approved',
                $correction,
                $before,
                [
                    'status' => AttendanceCorrectionStatus::Approved->value,
                    'attendance_record_id' => $record->id,
                    'attendance_session_id' => $session->id,
                ],
                $request,
                ['employee_id' => $correction->employee_id]
            );

            return $correction->fresh();
        });
    }

    private function resolveRecord(AttendanceCorrectionRequest $correction): AttendanceRecord
    {
        $date = CarbonImmutable::parse($correction->attendance_date)->toDateString();

        $record = AttendanceRecord::query()
            ->where('employee_id', $correction->employee_id)
            ->whereDate('attendance_date', $date)
            ->first();

        if ($record !== null) {
            return $record;
        }

        return AttendanceRecord::create([
            'employee_id' => $correction->employee_id,
            'outsource_id' => null,
            'attendable_type' => 'employee',
            'attendance_date' => $date,
            'status' => AttendanceStatus::Incomplete->value,
        ]);
    }

    private function applySessionCorrection(
        AttendanceCorrectionRequest $correction,
        AttendanceRecord $record,
    ): AttendanceSession {
        $checkIn = $correction->requested_check_in_at !== null
            ? CarbonImmutable::parse($correction->requested_check_in_at)
            : null;
        $checkOut = $correction->requested_check_out_at !== null
            ? CarbonImmutable::parse($correction->requested_check_out_at)
            : null;

        $session = null;
        if ($correction->attendance_session_id !== null) {
            $session = AttendanceSession::query()
                ->whereKey($correction->attendance_session_id)
                ->where('attendance_record_id', $record->id)
                ->first();
        }

        if ($session === null && $checkIn === null) {
            $session = $record->sessions()
                ->where('status', 'open')
                ->orderByDesc('id')
                ->first()
                ?? $record->sessions()->orderByDesc('id')->first();
        }

        if ($session === null) {
            $session = new AttendanceSession([
                'attendance_record_id' => $record->id,
            ]);
        }

        if ($checkIn !== null) {
            $session->check_in_at = $checkIn;
        }

        if ($checkOut !== null) {
            $session->check_out_at = $checkOut;
        }

        if ($session->check_in_at === null) {
            throw new InvalidArgumentException('Cannot approve clock-out correction without an existing clock in.');
        }

        if ($session->check_out_at !== null) {
            $in = CarbonImmutable::parse($session->check_in_at);
            $out = CarbonImmutable::parse($session->check_out_at);
            if ($out->lessThanOrEqualTo($in)) {
                throw new InvalidArgumentException('Clock out must be after clock in.');
            }
            $session->duration_minutes = (int) $in->diffInMinutes($out);
            $session->status = 'closed';
        } else {
            $session->duration_minutes = null;
            $session->status = 'open';
        }

        $session->attendance_record_id = $record->id;
        $session->save();

        return $session;
    }

    private function refreshDailyStatus(AttendanceRecord $record): void
    {
        $record->load('sessions');
        $hasOpen = $record->sessions->contains(fn (AttendanceSession $session) => $session->status === 'open');
        $hasClosed = $record->sessions->contains(fn (AttendanceSession $session) => $session->status === 'closed');

        if ($hasOpen) {
            $record->status = AttendanceStatus::Incomplete->value;
        } elseif ($hasClosed) {
            $record->status = AttendanceStatus::Present->value;
        } else {
            $record->status = AttendanceStatus::Absent->value;
        }

        $record->save();
    }
}
