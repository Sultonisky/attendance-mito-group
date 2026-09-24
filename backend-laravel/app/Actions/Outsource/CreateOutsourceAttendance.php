<?php

namespace App\Actions\Outsource;

use App\Actions\Action;
use App\Actions\Audit\RecordAuditAction;
use App\Enums\AttendanceEventType;
use App\Enums\AttendanceSessionStatus;
use App\Enums\AttendanceStatus;
use App\Models\AttendanceEvent;
use App\Models\AttendanceRecord;
use App\Models\AttendanceSession;
use App\Models\Outsource;
use App\Models\User;
use App\Models\WorkLocationPin;
use App\Services\Outsource\ResolveOutsourceAllowedPins;
use App\Support\AttendanceDateTime;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Admin manual create of an outsource daily attendance record (no GPS/face).
 */
class CreateOutsourceAttendance implements Action
{
    public function __construct(
        protected ResolveOutsourceAllowedPins $resolveAllowedPins,
        protected RecordAuditAction $audit,
    ) {}

    /**
     * @param  array{
     *   outsource_id: int,
     *   attendance_date: string,
     *   pin_id: int,
     *   check_in_at: string,
     *   check_out_at?: string|null
     * }  $input
     */
    public function execute(array $input, ?User $actor, ?Request $request = null): AttendanceRecord
    {
        return DB::transaction(function () use ($input, $actor, $request): AttendanceRecord {
            $outsource = Outsource::query()->findOrFail((int) $input['outsource_id']);

            if ($outsource->status !== 'active') {
                throw new InvalidArgumentException('Outsource is inactive.');
            }

            $attendanceDate = (string) $input['attendance_date'];
            $exists = AttendanceRecord::query()
                ->where('outsource_id', $outsource->id)
                ->whereDate('attendance_date', $attendanceDate)
                ->exists();

            if ($exists) {
                throw new InvalidArgumentException('Attendance already exists for this outsource on that date.');
            }

            $pin = $this->resolveAllowedPins->assertPinAllowed($outsource, (int) $input['pin_id']);
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
                'employee_id' => null,
                'outsource_id' => $outsource->id,
                'attendable_type' => 'outsource',
                'attendance_date' => $attendanceDate,
                'status' => $incomplete
                    ? AttendanceStatus::Incomplete->value
                    : AttendanceStatus::Present->value,
            ]);

            $this->writeSessionAndEvents($record, $pin, $checkInAt, $checkOutAt, $duration, $incomplete);

            $this->audit->execute(
                $actor?->getKey(),
                'outsource_attendance.created',
                $record,
                null,
                [
                    'outsource_id' => $outsource->id,
                    'attendance_date' => $attendanceDate,
                    'pin_id' => $pin->id,
                    'check_in_at' => AttendanceDateTime::toApi($checkInAt),
                    'check_out_at' => AttendanceDateTime::toApi($checkOutAt),
                    'status' => $record->status,
                    'duration_minutes' => $duration,
                ],
                $request,
            );

            return $record->fresh(['sessions', 'events', 'outsource']) ?? $record;
        });
    }

    protected function writeSessionAndEvents(
        AttendanceRecord $record,
        WorkLocationPin $pin,
        CarbonImmutable $checkInAt,
        ?CarbonImmutable $checkOutAt,
        ?int $durationMinutes,
        bool $incomplete,
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
            'employee_id' => null,
            'outsource_id' => $record->outsource_id,
            'work_location_pin_id' => $pin->id,
            'attendance_id' => $record->id,
            'attendance_session_id' => $session->id,
            'event_type' => AttendanceEventType::CheckIn->value,
            'occurred_at' => $checkInAt,
            'latitude' => $pin->latitude,
            'longitude' => $pin->longitude,
            'source' => 'admin',
            'device_metadata' => [],
        ]);

        if (! $incomplete && $checkOutAt !== null) {
            AttendanceEvent::query()->create([
                'employee_id' => null,
                'outsource_id' => $record->outsource_id,
                'work_location_pin_id' => $pin->id,
                'attendance_id' => $record->id,
                'attendance_session_id' => $session->id,
                'event_type' => AttendanceEventType::CheckOut->value,
                'occurred_at' => $checkOutAt,
                'latitude' => $pin->latitude,
                'longitude' => $pin->longitude,
                'source' => 'admin',
                'device_metadata' => [],
            ]);
        }
    }

    /**
     * Parse admin wall-clock input as Asia/Jakarta, return UTC instant for storage.
     */
    protected function parseJakartaInstant(string $raw): CarbonImmutable
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
