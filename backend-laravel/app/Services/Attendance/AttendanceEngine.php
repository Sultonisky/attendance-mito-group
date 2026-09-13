<?php

namespace App\Services\Attendance;

use App\Enums\AttendanceEventType;
use App\Enums\AttendanceSessionStatus;
use App\Enums\AttendanceStatus;
use App\Models\AttendanceRecord;
use App\Models\AttendanceSession;
use App\Models\Employee;
use App\Models\Shift;
use App\Models\WorkLocation;
use Carbon\CarbonImmutable;

/**
 * Core attendance domain engine.
 *
 * The engine evaluates business rules and returns decisions. It does not
 * perform persistence itself. Persistence is the responsibility of the
 * caller (typically an Action within a DB::transaction).
 */
class AttendanceEngine
{
    public function __construct(
        protected GeofenceService $geofence,
        protected ScheduleResolver $schedule,
        protected PolicyEvaluator $policies,
    ) {}

    /**
     * Evaluate a check-in attempt.
     *
     * @param  array<string, mixed>|null  $context
     * @return array{status: AttendanceStatus, record: AttendanceRecord|null, session: AttendanceSession|null, geofence: array, policy: array, events: array<int, array<string, mixed>>, error: string|null}
     */
    public function evaluateCheckIn(Employee $employee, CarbonImmutable $occurredAt, ?array $context = []): array
    {
        if ($employee->employment_status !== 'permanent' && $employee->employment_status !== 'contract') {
            return [
                'status' => AttendanceStatus::Absent,
                'record' => null,
                'session' => null,
                'geofence' => [
                    'passed' => true,
                    'distance_meters' => null,
                    'method' => 'skipped',
                ],
                'policy' => [
                    'allowed' => false,
                    'reason' => 'Employee employment status is inactive.',
                    'policies' => [],
                ],
                'events' => [],
                'error' => 'Employee employment status is inactive.',
            ];
        }

        $date = $occurredAt->toDateString();

        $scheduleResult = $this->schedule->resolveForDateTime($employee, $occurredAt);

        if ($scheduleResult['schedule'] === null) {
            return [
                'status' => AttendanceStatus::Absent,
                'record' => null,
                'session' => null,
                'geofence' => [
                    'passed' => true,
                    'distance_meters' => null,
                    'method' => 'skipped',
                ],
                'policy' => [
                    'allowed' => true,
                    'reason' => null,
                    'policies' => [],
                ],
                'events' => [],
                'error' => 'No active schedule found for this date.',
            ];
        }

        $policyResult = $this->policies->evaluateCheckIn($employee, $occurredAt);

        if (! $policyResult['allowed']) {
            return [
                'status' => AttendanceStatus::Absent,
                'record' => null,
                'session' => null,
                'geofence' => [
                    'passed' => true,
                    'distance_meters' => null,
                    'method' => 'skipped',
                ],
                'policy' => $policyResult,
                'events' => [],
                'error' => $policyResult['reason'],
            ];
        }

        $geofenceResult = [
            'passed' => true,
            'distance_meters' => null,
            'method' => 'skipped',
        ];

        if (isset($context['latitude'], $context['longitude'], $context['work_location_id']) && $context['work_location_id'] !== null) {
            $workLocation = WorkLocation::find($context['work_location_id']);

            if ($workLocation !== null) {
                $geofenceResult = $this->geofence->evaluate(
                    $workLocation,
                    (float) $context['latitude'],
                    (float) $context['longitude']
                );

                if (! $geofenceResult['passed']) {
                    return [
                        'status' => AttendanceStatus::Incomplete,
                        'record' => null,
                        'session' => null,
                        'geofence' => $geofenceResult,
                        'policy' => $policyResult,
                        'events' => [],
                        'error' => 'Employee is outside the approved work location geofence.',
                    ];
                }
            }
        }

        $status = $this->determineStatus($occurredAt, $scheduleResult['shift']);

        $events = [
            [
                'event_type' => AttendanceEventType::CheckIn->value,
                'occurred_at' => $occurredAt,
                'latitude' => $context['latitude'] ?? null,
                'longitude' => $context['longitude'] ?? null,
                'accuracy_meters' => $context['accuracy_meters'] ?? null,
                'source' => $context['source'] ?? 'app',
                'device_metadata' => $context['device_metadata'] ?? null,
            ],
        ];

        return [
            'status' => $status,
            'record' => null,
            'session' => null,
            'geofence' => $geofenceResult,
            'policy' => $policyResult,
            'events' => $events,
            'error' => null,
        ];
    }

    /**
     * Evaluate a check-out attempt.
     *
     * @return array{status: AttendanceStatus, record: AttendanceRecord, session: AttendanceSession, geofence: array, policy: array, events: array<int, array<string, mixed>>, error: string|null}
     */
    public function evaluateCheckOut(Employee $employee, CarbonImmutable $occurredAt, ?array $context = []): array
    {
        if ($employee->employment_status !== 'permanent' && $employee->employment_status !== 'contract') {
            return [
                'status' => AttendanceStatus::Absent,
                'record' => null,
                'session' => null,
                'geofence' => [
                    'passed' => true,
                    'distance_meters' => null,
                    'method' => 'skipped',
                ],
                'policy' => [
                    'allowed' => false,
                    'reason' => 'Employee employment status is inactive.',
                    'policies' => [],
                ],
                'events' => [],
                'error' => 'Employee employment status is inactive.',
            ];
        }

        $date = $occurredAt->toDateString();

        $session = AttendanceSession::query()
            ->whereHas('attendanceRecord', function ($query) use ($employee) {
                $query->where('employee_id', $employee->id);
            })
            ->where('status', AttendanceSessionStatus::Open->value)
            ->orderByDesc('check_in_at')
            ->first();

        if ($session === null) {
            return [
                'status' => AttendanceStatus::Incomplete,
                'record' => null,
                'session' => null,
                'geofence' => [
                    'passed' => true,
                    'distance_meters' => null,
                    'method' => 'skipped',
                ],
                'policy' => [
                    'allowed' => false,
                    'reason' => 'No open attendance session found.',
                    'policies' => [],
                ],
                'events' => [],
                'error' => 'No open attendance session found.',
            ];
        }

        $record = $session->attendanceRecord;

        $policyResult = $this->policies->evaluateCheckOut($employee, $occurredAt);

        if (! $policyResult['allowed']) {
            return [
                'status' => AttendanceStatus::Incomplete,
                'record' => $record,
                'session' => $session,
                'geofence' => [
                    'passed' => true,
                    'distance_meters' => null,
                    'method' => 'skipped',
                ],
                'policy' => $policyResult,
                'events' => [],
                'error' => $policyResult['reason'],
            ];
        }

        $geofenceResult = [
            'passed' => true,
            'distance_meters' => null,
            'method' => 'skipped',
        ];

        if (isset($context['latitude'], $context['longitude'], $context['work_location_id']) && $context['work_location_id'] !== null) {
            $workLocation = WorkLocation::find($context['work_location_id']);

            if ($workLocation !== null) {
                $geofenceResult = $this->geofence->evaluate(
                    $workLocation,
                    (float) $context['latitude'],
                    (float) $context['longitude']
                );
            }
        }

        $events = [
            [
                'event_type' => AttendanceEventType::CheckOut->value,
                'occurred_at' => $occurredAt,
                'latitude' => $context['latitude'] ?? null,
                'longitude' => $context['longitude'] ?? null,
                'accuracy_meters' => $context['accuracy_meters'] ?? null,
                'source' => $context['source'] ?? 'app',
                'device_metadata' => $context['device_metadata'] ?? null,
            ],
        ];

        return [
            'status' => $record->status,
            'record' => $record,
            'session' => $session,
            'geofence' => $geofenceResult,
            'policy' => $policyResult,
            'events' => $events,
            'error' => null,
        ];
    }

    /**
     * Determine the daily attendance status based on schedule context.
     *
     * Check-in always produces a base status of Present. Lateness is tracked
     * separately by the Attendance Session / Daily Recap engines.
     */
    private function determineStatus(?CarbonImmutable $occurredAt, ?Shift $shift): AttendanceStatus
    {
        if ($shift === null) {
            return AttendanceStatus::Absent;
        }

        return AttendanceStatus::Present;
    }
}
