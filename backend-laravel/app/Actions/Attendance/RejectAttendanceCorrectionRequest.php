<?php

namespace App\Actions\Attendance;

use App\Actions\Audit\RecordAuditAction;
use App\Enums\AttendanceCorrectionStatus;
use App\Models\AttendanceCorrectionRequest;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class RejectAttendanceCorrectionRequest
{
    public function __construct(
        private RecordAuditAction $audit,
    ) {}

    public function execute(
        AttendanceCorrectionRequest $correction,
        User $actor,
        ?string $reason = null,
        ?Request $request = null,
    ): AttendanceCorrectionRequest {
        if ($correction->status !== AttendanceCorrectionStatus::Pending->value) {
            throw new InvalidArgumentException('Only pending correction requests can be rejected.');
        }

        return DB::transaction(function () use ($correction, $actor, $reason, $request): AttendanceCorrectionRequest {
            $before = $correction->only(['status', 'reviewed_by', 'reviewed_at', 'rejection_reason']);

            $correction->update([
                'status' => AttendanceCorrectionStatus::Rejected->value,
                'reviewed_by' => $actor->id,
                'reviewed_at' => now(),
                'rejection_reason' => $reason,
            ]);

            $this->audit->execute(
                $actor->getKey(),
                'attendance.correction_rejected',
                $correction,
                $before,
                [
                    'status' => AttendanceCorrectionStatus::Rejected->value,
                    'rejection_reason' => $reason,
                ],
                $request,
                ['employee_id' => $correction->employee_id]
            );

            return $correction->fresh();
        });
    }
}
