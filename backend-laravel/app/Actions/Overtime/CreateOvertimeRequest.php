<?php

namespace App\Actions\Overtime;

use App\Actions\Audit\RecordAuditAction;
use App\Domain\Overtime\Engines\OvertimeEngine;
use App\Domain\Overtime\Exceptions\InvalidOvertimeStateException;
use App\Models\AttendanceRecord;
use App\Models\Employee;
use App\Models\OvertimeRecord;
use App\Models\OvertimeRequest;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Create an overtime request from an employee.
 */
class CreateOvertimeRequest
{
    public function __construct(
        private OvertimeEngine $engine,
        private RecordAuditAction $audit,
    ) {}

    /**
     * @param  array{attendance_id: int, requested_minutes: int, reason?: ?string}  $input
     */
    public function execute(Employee $employee, array $input, ?User $actor = null, ?Request $request = null): OvertimeRequest
    {
        $attendance = AttendanceRecord::where('employee_id', $employee->id)
            ->whereKey($input['attendance_id'])
            ->firstOrFail();

        $date = CarbonImmutable::parse($attendance->attendance_date)->startOfDay();
        $requestedMinutes = (int) $input['requested_minutes'];

        if ($requestedMinutes <= 0) {
            throw new InvalidOvertimeStateException('Requested minutes must be positive.');
        }

        $calculation = $this->engine->calculateForDate($employee, $date);
        $potentialMinutes = (int) ($calculation->potentialMinutes ?? 0);

        if ($requestedMinutes > $potentialMinutes) {
            throw new InvalidOvertimeStateException('Requested minutes exceed calculated potential overtime.');
        }

        return DB::transaction(function () use ($employee, $attendance, $date, $requestedMinutes, $input, $actor, $request, $calculation): OvertimeRequest {
            $overtime = OvertimeRecord::where('employee_id', $employee->id)
                ->whereDate('date', $date->toDateString())
                ->first();

            if ($overtime === null) {
                $overtime = OvertimeRecord::create([
                    'employee_id' => $employee->id,
                    'date' => $date->toDateString(),
                    'potential_minutes' => $calculation->potentialMinutes ?? 0,
                    'requested_minutes' => $requestedMinutes,
                    'approved_minutes' => null,
                    'actual_minutes' => null,
                    'status' => 'requested',
                ]);
            } else {
                $overtime->update([
                    'requested_minutes' => $requestedMinutes,
                    'status' => 'requested',
                ]);
            }

            $otRequest = OvertimeRequest::create([
                'employee_id' => $employee->id,
                'attendance_id' => $attendance->id,
                'date' => $date->toDateString(),
                'requested_minutes' => $requestedMinutes,
                'approved_minutes' => null,
                'status' => 'pending',
                'reason' => $input['reason'] ?? null,
            ]);

            $overtime->update(['overtime_request_id' => $otRequest->id]);

            $this->audit->execute(
                $actor?->getKey(),
                'overtime.request_created',
                $otRequest,
                null,
                ['status' => 'pending', 'requested_minutes' => $requestedMinutes],
                $request,
                ['employee_id' => $employee->id]
            );

            return $otRequest->fresh() ?? $otRequest;
        });
    }
}
