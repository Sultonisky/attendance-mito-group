<?php

namespace App\Actions\Attendance;

use App\Actions\Audit\RecordAuditAction;
use App\Enums\AttendanceEventType;
use App\Enums\AttendanceSessionStatus;
use App\Enums\AttendanceStatus;
use App\Models\AttendanceEvent;
use App\Models\AttendanceRecord;
use App\Models\AttendanceSession;
use App\Models\Employee;
use App\Models\User;
use App\Support\AttendanceDateTime;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Admin manual create of an employee daily attendance record (no GPS/face).
 */
class CreateEmployeeAttendance
{
    public function __construct(
        private RecordAuditAction $audit,
    ) {}

    /**
     * @param  array{
     *   employee_id?: int|null,
     *   hris_employee_id?: string|null,
     *   nik?: string|null,
     *   work_location_id?: int|null,
     *   attendance_date: string,
     *   check_in_at: string,
     *   check_out_at?: string|null
     * }  $input
     */
    public function execute(array $input, ?User $actor, ?Request $request = null): AttendanceRecord
    {
        return DB::transaction(function () use ($input, $actor, $request): AttendanceRecord {
            $employee = $this->resolveEmployee($input);
            $workLocationId = isset($input['work_location_id']) ? (int) $input['work_location_id'] : null;

            if ($workLocationId !== null && ! $employee->activeWorkLocations()->whereKey($workLocationId)->exists()) {
                throw new InvalidArgumentException('Selected work location is not assigned to this employee.');
            }

            $attendanceDate = (string) $input['attendance_date'];

            $exists = AttendanceRecord::query()
                ->where('employee_id', $employee->id)
                ->whereDate('attendance_date', $attendanceDate)
                ->exists();

            if ($exists) {
                throw new InvalidArgumentException('Attendance already exists for this employee on that date.');
            }

            $checkInAt = $this->parseJakartaInstant((string) $input['check_in_at']);
            $checkOutRaw = $input['check_out_at'] ?? null;
            $checkOutAt = ($checkOutRaw !== null && trim((string) $checkOutRaw) !== '')
                ? $this->parseJakartaInstant((string) $checkOutRaw)
                : null;

            if ($checkOutAt !== null && $checkOutAt->lt($checkInAt)) {
                throw new InvalidArgumentException('Clock out must be on or after clock in.');
            }

            $incomplete = $checkOutAt === null;
            $duration = $incomplete ? null : (int) $checkInAt->diffInMinutes($checkOutAt);

            $record = AttendanceRecord::query()->create([
                'employee_id' => $employee->id,
                'outsource_id' => null,
                'attendable_type' => 'employee',
                'attendance_date' => $attendanceDate,
                'status' => $incomplete
                    ? AttendanceStatus::Incomplete->value
                    : AttendanceStatus::Present->value,
            ]);

            $this->writeSessionAndEvents($record, $checkInAt, $checkOutAt, $duration, $incomplete, $workLocationId);

            $this->audit->execute(
                $actor?->getKey(),
                'attendance.created',
                $record,
                null,
                [
                    'employee_id' => $employee->id,
                    'attendance_date' => $attendanceDate,
                    'check_in_at' => AttendanceDateTime::toApi($checkInAt),
                    'check_out_at' => AttendanceDateTime::toApi($checkOutAt),
                    'status' => $record->status,
                    'duration_minutes' => $duration,
                    'employee_work_location_id' => $workLocationId,
                ],
                $request,
                ['employee_id' => $employee->id],
            );

            return $record->fresh(['sessions', 'events', 'employee']) ?? $record;
        });
    }

    private function writeSessionAndEvents(
        AttendanceRecord $record,
        CarbonImmutable $checkInAt,
        ?CarbonImmutable $checkOutAt,
        ?int $durationMinutes,
        bool $incomplete,
        ?int $workLocationId,
    ): void {
        $session = AttendanceSession::query()->create([
            'attendance_record_id' => $record->id,
            'check_in_at' => $checkInAt,
            'check_out_at' => $checkOutAt,
            'duration_minutes' => $durationMinutes,
            'status' => $incomplete
                ? AttendanceSessionStatus::Open->value
                : AttendanceSessionStatus::Closed->value,
        ]);

        AttendanceEvent::query()->create([
            'employee_id' => $record->employee_id,
            'outsource_id' => null,
            'work_location_pin_id' => null,
            'employee_work_location_id' => $workLocationId,
            'attendance_id' => $record->id,
            'attendance_session_id' => $session->id,
            'event_type' => AttendanceEventType::CheckIn->value,
            'occurred_at' => $checkInAt,
            'latitude' => null,
            'longitude' => null,
            'source' => 'admin',
            'device_metadata' => [],
        ]);

        if (! $incomplete && $checkOutAt !== null) {
            AttendanceEvent::query()->create([
                'employee_id' => $record->employee_id,
                'outsource_id' => null,
                'work_location_pin_id' => null,
                'employee_work_location_id' => $workLocationId,
                'attendance_id' => $record->id,
                'attendance_session_id' => $session->id,
                'event_type' => AttendanceEventType::CheckOut->value,
                'occurred_at' => $checkOutAt,
                'latitude' => null,
                'longitude' => null,
                'source' => 'admin',
                'device_metadata' => [],
            ]);
        }
    }

    /**
     * Resolve an HRIS identity to an already-linked Attendance employee.
     *
     * @param  array{employee_id?: int|null, hris_employee_id?: string|null, nik?: string|null}  $input
     */
    private function resolveEmployee(array $input): Employee
    {
        if (isset($input['hris_employee_id'])) {
            $employeeByNik = isset($input['nik'])
                ? Employee::query()->where('nik', $input['nik'])->first()
                : null;
            $employeeByCode = Employee::query()
                ->where('employee_code', $input['hris_employee_id'])
                ->first();

            if ($employeeByNik !== null && $employeeByCode !== null && $employeeByNik->isNot($employeeByCode)) {
                throw new InvalidArgumentException('The HRIS NIK and employee ID point to different Attendance employee records.');
            }

            $employee = $employeeByNik ?? $employeeByCode;
            if ($employee === null) {
                throw new InvalidArgumentException('No existing Attendance employee matches this HRIS person. Link the Attendance employee record first.');
            }

            if ($employee->nik !== null && $employee->nik !== $input['nik']) {
                throw new InvalidArgumentException('This Attendance employee record is already linked to a different NIK.');
            }

            return $employee;
        }

        return Employee::query()->findOrFail((int) $input['employee_id']);
    }

    private function parseJakartaInstant(string $raw): CarbonImmutable
    {
        $value = trim(str_replace('T', ' ', $raw));
        $tz = AttendanceDateTime::timezone();

        foreach (['Y-m-d H:i:s', 'Y-m-d H:i'] as $format) {
            try {
                $parsed = CarbonImmutable::createFromFormat($format, $value, $tz);
                if ($parsed !== false) {
                    return $parsed->utc();
                }
            } catch (\Throwable) {
                // try next
            }
        }

        try {
            return CarbonImmutable::parse($raw, $tz)->utc();
        } catch (\Throwable) {
            throw new InvalidArgumentException('Invalid date/time format.');
        }
    }
}
