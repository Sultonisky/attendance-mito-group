<?php

namespace App\Actions\Attendance;

use App\Actions\Audit\RecordAuditAction;
use App\Enums\AttendanceCorrectionStatus;
use App\Models\AttendanceCorrectionRequest;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class CancelAttendanceCorrectionRequest
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
            throw new InvalidArgumentException('Only pending correction requests can be cancelled.');
        }

        return DB::transaction(function () use ($correction, $actor, $request): AttendanceCorrectionRequest {
            $before = $correction->only(['status']);

            $correction->update([
                'status' => AttendanceCorrectionStatus::Cancelled->value,
            ]);

            $this->audit->execute(
                $actor->getKey(),
                'attendance.correction_cancelled',
                $correction,
                $before,
                ['status' => AttendanceCorrectionStatus::Cancelled->value],
                $request,
                ['employee_id' => $correction->employee_id]
            );

            return $correction->fresh();
        });
    }
}
