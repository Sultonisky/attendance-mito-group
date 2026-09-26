<?php

namespace App\Actions\Attendance;

use App\Actions\Audit\RecordAuditAction;
use App\Enums\AttendanceCorrectionStatus;
use App\Enums\AttendanceCorrectionType;
use App\Models\AttendanceCorrectionRequest;
use App\Models\AttendanceRecord;
use App\Models\Employee;
use App\Models\User;
use App\Support\AttendanceDateTime;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Create a pending attendance correction request for forgotten clock in/out.
 */
class CreateAttendanceCorrectionRequest
{
    public function __construct(
        private RecordAuditAction $audit,
    ) {}

    /**
     * @param  array{
     *   request_type: string,
     *   attendance_date: string,
     *   requested_check_in_at?: ?string,
     *   requested_check_out_at?: ?string,
     *   reason: string
     * }  $input
     */
    public function execute(Employee $employee, array $input, ?User $actor = null, ?Request $request = null): AttendanceCorrectionRequest
    {
        $type = AttendanceCorrectionType::from($input['request_type']);
        $date = CarbonImmutable::parse($input['attendance_date'])->toDateString();

        $checkIn = $this->parseOptionalInstant($input['requested_check_in_at'] ?? null);
        $checkOut = $this->parseOptionalInstant($input['requested_check_out_at'] ?? null);

        $this->assertTypeRequirements($type, $checkIn, $checkOut);
        $this->assertTimesMatchDate($date, $checkIn, $checkOut);

        if ($checkIn !== null && $checkOut !== null && $checkOut->lessThanOrEqualTo($checkIn)) {
            throw new InvalidArgumentException('Clock out must be after clock in.');
        }

        return DB::transaction(function () use ($employee, $type, $date, $checkIn, $checkOut, $input, $actor, $request): AttendanceCorrectionRequest {
            $record = AttendanceRecord::query()
                ->where('employee_id', $employee->id)
                ->whereDate('attendance_date', $date)
                ->first();

            $session = null;
            if ($record !== null && $type === AttendanceCorrectionType::ClockOut) {
                $session = $record->sessions()
                    ->where('status', 'open')
                    ->orderByDesc('id')
                    ->first()
                    ?? $record->sessions()->orderByDesc('id')->first();
            }

            $correction = AttendanceCorrectionRequest::create([
                'employee_id' => $employee->id,
                'attendance_record_id' => $record?->id,
                'attendance_session_id' => $session?->id,
                'request_type' => $type->value,
                'attendance_date' => $date,
                'requested_check_in_at' => $checkIn,
                'requested_check_out_at' => $checkOut,
                'reason' => $input['reason'],
                'status' => AttendanceCorrectionStatus::Pending->value,
            ]);

            $this->audit->execute(
                $actor?->getKey(),
                'attendance.correction_requested',
                $correction,
                null,
                [
                    'status' => AttendanceCorrectionStatus::Pending->value,
                    'request_type' => $type->value,
                    'attendance_date' => $date,
                ],
                $request,
                ['employee_id' => $employee->id]
            );

            return $correction;
        });
    }

    private function parseOptionalInstant(?string $value): ?CarbonImmutable
    {
        if ($value === null || trim($value) === '') {
            return null;
        }

        return CarbonImmutable::parse($value)->timezone(AttendanceDateTime::timezone());
    }

    private function assertTypeRequirements(
        AttendanceCorrectionType $type,
        ?CarbonImmutable $checkIn,
        ?CarbonImmutable $checkOut,
    ): void {
        if ($type === AttendanceCorrectionType::ClockIn && $checkIn === null) {
            throw new InvalidArgumentException('Clock in time is required.');
        }

        if ($type === AttendanceCorrectionType::ClockOut && $checkOut === null) {
            throw new InvalidArgumentException('Clock out time is required.');
        }

        if ($type === AttendanceCorrectionType::Both && ($checkIn === null || $checkOut === null)) {
            throw new InvalidArgumentException('Clock in and clock out times are required.');
        }
    }

    private function assertTimesMatchDate(
        string $date,
        ?CarbonImmutable $checkIn,
        ?CarbonImmutable $checkOut,
    ): void {
        foreach ([$checkIn, $checkOut] as $instant) {
            if ($instant === null) {
                continue;
            }

            if (AttendanceDateTime::toBusinessDate($instant) !== $date) {
                throw new InvalidArgumentException('Requested times must fall on the attendance date.');
            }
        }
    }
}
