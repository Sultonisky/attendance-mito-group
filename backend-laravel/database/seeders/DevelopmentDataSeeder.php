<?php

namespace Database\Seeders;

use App\Enums\AttendanceSessionStatus;
use App\Enums\AttendanceStatus;
use App\Enums\OvertimeStatus;
use App\Enums\PenaltySource;
use App\Enums\PenaltyStatus;
use App\Enums\PenaltyViolationType;
use App\Enums\RecordStatus;
use App\Models\AttendanceEvent;
use App\Models\AttendanceRecord;
use App\Models\AttendanceSession;
use App\Models\City;
use App\Models\Employee;
use App\Models\LeaveBalance;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Models\MonthlyRecap;
use App\Models\Outsource;
use App\Models\OvertimeRecord;
use App\Models\OvertimeRequest;
use App\Models\PenaltyRecord;
use App\Models\PenaltyRule;
use App\Models\Policy;
use App\Models\PolicyAssignment;
use App\Models\ScheduleAssignment;
use App\Models\Shift;
use App\Models\User;
use App\Models\WorkLocation;
use App\Models\WorkSchedule;
use Carbon\CarbonImmutable;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Role;

/**
 * DEVELOPMENT ONLY.
 *
 * Populates the local development database with realistic demo data using
 * the model factories. Never runs in production (guarded by DatabaseSeeder).
 *
 * Idempotent: foundation anchors use firstOrCreate; attendance / leave /
 * overtime / penalty / outsource rows skip when already present for the
 * same demo subject + date.
 */
class DevelopmentDataSeeder extends Seeder
{
    use WithoutModelEvents;

    protected const EMPLOYEE_PREFIX = 'EMP-DEV';

    protected const LINKED_EMPLOYEES = 4;

    protected const UNLINKED_EMPLOYEES = 6;

    /** @var list<string> */
    protected const BRANCHES = [
        'Jakarta',
        'Bandung',
        'Surabaya',
        'Semarang',
        'Medan',
        'Yogyakarta',
    ];

    public function run(): void
    {
        $context = $this->seedFoundationData();
        $this->seedLeaveTypesAndPenaltyRules();

        if (! Employee::where('employee_code', 'like', self::EMPLOYEE_PREFIX.'%')->exists()) {
            $this->seedDemoEmployees($context);
        } else {
            $this->backfillDemoEmployeeBranches();
        }

        $this->seedAttendanceHistory($context);
        $this->seedLeaveOvertimePenaltyAndRecaps($context);
        $this->seedOutsourceAttendance($context);
    }

    /**
     * Ensure older demo employees have branch/email for dashboard table rows.
     */
    protected function backfillDemoEmployeeBranches(): void
    {
        $employees = Employee::where('employee_code', 'like', self::EMPLOYEE_PREFIX.'%')
            ->orderBy('id')
            ->get();

        foreach ($employees as $index => $employee) {
            $updates = [];

            if (blank($employee->branch)) {
                $updates['branch'] = self::BRANCHES[$index % count(self::BRANCHES)];
            }

            if (blank($employee->email)) {
                $updates['email'] = 'emp.dev.'.($index + 1).'@mitogroup.com';
            }

            if ($updates !== []) {
                $employee->forceFill($updates)->save();
            }
        }
    }

    /**
     * @return array{hq: WorkLocation, stores: list<WorkLocation>, schedule: WorkSchedule, shift: Shift, policy: Policy}
     */
    protected function seedFoundationData(): array
    {
        City::firstOrCreate(
            ['code' => 'CITY-DEV-JKT'],
            ['name' => 'Jakarta', 'status' => 'active'],
        );

        if (City::count() < 3) {
            City::factory()->count(2)->create();
        }

        $hq = WorkLocation::firstOrCreate(
            ['code' => 'LOC-DEV-HQ'],
            [
                'name' => 'MITO HQ',
                'latitude' => -6.1754,
                'longitude' => 106.8272,
                'radius_meters' => 150,
                'status' => 'active',
            ],
        );

        $stores = WorkLocation::query()
            ->where('code', '!=', 'LOC-DEV-HQ')
            ->where('status', 'active')
            ->orderBy('id')
            ->limit(3)
            ->get();

        if ($stores->count() < 3) {
            $stores = $stores->merge(
                WorkLocation::factory()->count(3 - $stores->count())->create()
            )->values();
        }

        $schedule = WorkSchedule::firstOrCreate(
            ['code' => 'SCH-DEV-OFFICE'],
            [
                'name' => 'Office Standard (dev)',
                'description' => 'Demo office schedule 09:00-18:00.',
                'status' => RecordStatus::Active->value,
                'effective_from' => now()->subYear()->toDateString(),
                'effective_to' => null,
            ],
        );

        $shift = Shift::firstOrCreate(
            ['work_schedule_id' => $schedule->id, 'name' => 'Office Shift (dev)'],
            [
                'start_time' => '09:00:00',
                'end_time' => '18:00:00',
                'break_start' => '12:00:00',
                'break_end' => '13:00:00',
                'cross_midnight' => false,
            ],
        );

        $policy = Policy::firstOrCreate(
            ['code' => 'POL-DEV-STANDARD'],
            [
                'name' => 'Standard Attendance Policy (dev)',
                'description' => 'Demo policy: no check-in/out blocks.',
                'status' => 'active',
                'effective_from' => now()->subYear()->toDateString(),
                'effective_to' => null,
                'configuration' => [
                    'attendance' => [
                        'check_in_blocked' => false,
                        'check_out_blocked' => false,
                    ],
                ],
            ],
        );

        return [
            'hq' => $hq,
            'stores' => $stores->all(),
            'schedule' => $schedule,
            'shift' => $shift,
            'policy' => $policy,
        ];
    }

    protected function seedLeaveTypesAndPenaltyRules(): void
    {
        LeaveType::firstOrCreate(
            ['code' => 'annual_leave'],
            [
                'name' => 'Annual Leave',
                'category' => 'annual',
                'deducts_annual_balance' => true,
                'description' => 'Yearly leave quota.',
                'status' => RecordStatus::Active->value,
            ],
        );

        LeaveType::firstOrCreate(
            ['code' => 'sick_leave'],
            [
                'name' => 'Sick Leave',
                'category' => 'special',
                'deducts_annual_balance' => false,
                'description' => 'Paid sick leave (does not deduct annual quota).',
                'status' => RecordStatus::Active->value,
            ],
        );

        foreach ([
            ['late', 'Late Check-in', 15, 5.0],
            ['absence', 'Absence', null, 10.0],
            ['early_checkout', 'Early Check-out', 30, 3.0],
            ['incomplete_attendance', 'Incomplete Attendance (Open Session)', null, 2.0],
        ] as [$violation, $name, $threshold, $points]) {
            PenaltyRule::firstOrCreate(
                ['code' => $violation],
                [
                    'name' => $name,
                    'description' => "Auto-generated demo rule for {$name}.",
                    'points' => $points,
                    'threshold' => $threshold,
                    'configuration' => ['violation_type' => $violation],
                    'status' => RecordStatus::Active->value,
                ],
            );
        }
    }

    /**
     * @param array{hq: WorkLocation, stores: list<WorkLocation>, schedule: WorkSchedule, policy: Policy} $context
     */
    protected function seedDemoEmployees(array $context): void
    {
        $userRole = Role::where('name', 'USER')->first();

        $linked = Employee::factory()
            ->count(self::LINKED_EMPLOYEES)
            ->create();

        foreach ($linked as $index => $employee) {
            $user = User::factory()->create([
                'name' => $employee->full_name,
                'email' => 'demo'.($index + 1).'@example.com',
            ]);

            if ($userRole !== null) {
                $user->assignRole($userRole);
            }

            $employee->forceFill(['user_id' => $user->id])->save();
        }

        $unlinked = Employee::factory()
            ->count(self::UNLINKED_EMPLOYEES)
            ->create();

        $employees = $linked->merge($unlinked);

        foreach ($employees as $index => $employee) {
            $employee->forceFill([
                'employee_code' => self::EMPLOYEE_PREFIX.'-'.str_pad((string) ($index + 1), 3, '0', STR_PAD_LEFT),
                'branch' => self::BRANCHES[$index % count(self::BRANCHES)],
                'email' => $employee->email ?: 'emp.dev.'.($index + 1).'@mitogroup.com',
            ])->save();

            ScheduleAssignment::factory()
                ->forEmployee($employee)
                ->forSchedule($context['schedule'])
                ->effectiveFrom($employee->join_date->toDateString())
                ->openEnded()
                ->create();

            PolicyAssignment::factory()
                ->forEmployee($employee)
                ->forPolicy($context['policy'])
                ->effectiveFrom($employee->join_date->toDateString())
                ->openEnded()
                ->create();

            LeaveBalance::factory()
                ->for($employee)
                ->for(LeaveType::where('code', 'annual_leave')->firstOrFail())
                ->state([
                    'period' => now()->format('Y-m-d'),
                    'balance' => 12,
                    'expires_at' => now()->addYear()->format('Y-m-d'),
                ])
                ->create();
        }

        if (Outsource::count() < 3) {
            // Outsource master data comes from the import command
            // (OutsourceMasterDataImportService). No dummy outsource records
            // are created here to avoid polluting the real dataset.
        }
    }

    /**
     * @param array{hq: WorkLocation} $context
     */
    protected function seedAttendanceHistory(array $context): void
    {
        $employees = Employee::where('employee_code', 'like', self::EMPLOYEE_PREFIX.'%')
            ->orderBy('id')
            ->get();
        $today = CarbonImmutable::today();

        foreach ($employees as $index => $employee) {
            for ($day = 14; $day >= 0; $day--) {
                $date = $today->subDays($day);

                if ($date->isWeekend()) {
                    continue;
                }

                if (AttendanceRecord::query()
                    ->where('employee_id', $employee->id)
                    ->whereDate('attendance_date', $date->toDateString())
                    ->exists()) {
                    continue;
                }

                $this->seedAttendanceDay($employee, $date, $context['hq'], $index, $day === 0);
            }
        }
    }

    /**
     * Mixed statuses: today varies by employee index; history mostly present
     * with occasional late / incomplete / absent.
     */
    protected function seedAttendanceDay(
        Employee $employee,
        CarbonImmutable $date,
        WorkLocation $hq,
        int $employeeIndex,
        bool $isToday,
    ): void {
        $status = $this->resolveDemoStatus($employeeIndex, $date, $isToday);

        if ($status === AttendanceStatus::Absent->value) {
            AttendanceRecord::factory()
                ->forEmployee($employee)
                ->onDate($date->toDateString())
                ->status($status)
                ->create();

            return;
        }

        if ($status === AttendanceStatus::Leave->value) {
            AttendanceRecord::factory()
                ->forEmployee($employee)
                ->onDate($date->toDateString())
                ->status($status)
                ->create();

            return;
        }

        $isLate = $status === AttendanceStatus::Late->value;
        $isIncomplete = $status === AttendanceStatus::Incomplete->value;

        $checkInAt = $date->setTime($isLate ? 9 : 8, $isLate ? 25 : 55);
        $checkOutAt = $isIncomplete ? null : $date->setTime(18, 5);

        $record = AttendanceRecord::factory()
            ->forEmployee($employee)
            ->onDate($date->toDateString())
            ->status($status)
            ->create();

        $session = AttendanceSession::factory()
            ->forRecord($record)
            ->state([
                'check_in_at' => $checkInAt,
                'check_out_at' => $checkOutAt,
                'duration_minutes' => $isIncomplete ? null : 550,
                'status' => $isIncomplete
                    ? AttendanceSessionStatus::Open->value
                    : AttendanceSessionStatus::Closed->value,
            ])
            ->create();

        AttendanceEvent::factory()
            ->checkIn()
            ->forRecord($record)
            ->forSession($session)
            ->state([
                'occurred_at' => $checkInAt,
                'latitude' => $hq->latitude,
                'longitude' => $hq->longitude,
            ])
            ->create();

        if (! $isIncomplete && $checkOutAt !== null) {
            AttendanceEvent::factory()
                ->checkOut()
                ->forRecord($record)
                ->forSession($session)
                ->state([
                    'occurred_at' => $checkOutAt,
                    'latitude' => $hq->latitude,
                    'longitude' => $hq->longitude,
                ])
                ->create();
        }
    }

    protected function resolveDemoStatus(int $employeeIndex, CarbonImmutable $date, bool $isToday): string
    {
        if ($isToday) {
            return match ($employeeIndex % 5) {
                0 => AttendanceStatus::Present->value,
                1 => AttendanceStatus::Late->value,
                2 => AttendanceStatus::Leave->value,
                3 => AttendanceStatus::Incomplete->value,
                default => AttendanceStatus::Absent->value,
            };
        }

        $bucket = ($employeeIndex + $date->dayOfYear()) % 10;

        return match (true) {
            $bucket === 0 => AttendanceStatus::Late->value,
            $bucket === 1 => AttendanceStatus::Incomplete->value,
            $bucket === 2 => AttendanceStatus::Absent->value,
            default => AttendanceStatus::Present->value,
        };
    }

    /**
     * @param array{hq: WorkLocation} $context
     */
    protected function seedLeaveOvertimePenaltyAndRecaps(array $context): void
    {
        $employees = Employee::where('employee_code', 'like', self::EMPLOYEE_PREFIX.'%')
            ->orderBy('id')
            ->get();

        if ($employees->isEmpty()) {
            return;
        }

        $annual = LeaveType::where('code', 'annual_leave')->firstOrFail();
        $sick = LeaveType::where('code', 'sick_leave')->firstOrFail();
        $lateRule = PenaltyRule::where('code', 'late')->firstOrFail();
        $absenceRule = PenaltyRule::where('code', 'absence')->firstOrFail();
        $today = CarbonImmutable::today();
        $period = $today->format('Y-m');

        foreach ($employees as $index => $employee) {
            // Approved leave covering today for "On leave" KPI employees.
            if ($index % 5 === 2) {
                LeaveRequest::query()->firstOrCreate(
                    [
                        'employee_id' => $employee->id,
                        'start_date' => $today->toDateString(),
                        'end_date' => $today->toDateString(),
                        'leave_type_id' => $annual->id,
                    ],
                    [
                        'status' => 'approved',
                        'reason' => 'Demo annual leave (today).',
                        'approved_at' => now(),
                    ],
                );
            }

            // Recent leave history for reports.
            $leaveDay = $today->subDays(3 + ($index % 4));
            if (! $leaveDay->isWeekend()) {
                LeaveRequest::query()->firstOrCreate(
                    [
                        'employee_id' => $employee->id,
                        'start_date' => $leaveDay->toDateString(),
                        'end_date' => $leaveDay->toDateString(),
                        'leave_type_id' => $index % 2 === 0 ? $annual->id : $sick->id,
                    ],
                    [
                        'status' => $index % 3 === 0 ? 'pending' : 'approved',
                        'reason' => 'Demo leave request.',
                        'approved_at' => $index % 3 === 0 ? null : now()->subDays(2),
                    ],
                );
            }

            $otDate = $today->subDays(2 + ($index % 3));
            if (! $otDate->isWeekend()) {
                $attendance = AttendanceRecord::query()
                    ->where('employee_id', $employee->id)
                    ->whereDate('attendance_date', $otDate->toDateString())
                    ->first();

                if ($attendance && ! OvertimeRecord::query()
                    ->where('employee_id', $employee->id)
                    ->whereDate('date', $otDate->toDateString())
                    ->exists()) {
                    $request = OvertimeRequest::query()->create([
                        'employee_id' => $employee->id,
                        'attendance_id' => $attendance->id,
                        'date' => $otDate->toDateString(),
                        'requested_minutes' => 60 + ($index * 15),
                        'approved_minutes' => $index % 2 === 0 ? 60 + ($index * 15) : null,
                        'status' => $index % 2 === 0 ? 'approved' : 'pending',
                        'reason' => 'Demo overtime.',
                        'approved_at' => $index % 2 === 0 ? now()->subDay() : null,
                    ]);

                    OvertimeRecord::query()->create([
                        'employee_id' => $employee->id,
                        'attendance_id' => $attendance->id,
                        'overtime_request_id' => $request->id,
                        'date' => $otDate->toDateString(),
                        'potential_minutes' => 90 + ($index * 10),
                        'requested_minutes' => 60 + ($index * 15),
                        'approved_minutes' => $index % 2 === 0 ? 60 + ($index * 15) : null,
                        'actual_minutes' => $index % 2 === 0 ? 60 + ($index * 15) : null,
                        'status' => $index % 2 === 0
                            ? OvertimeStatus::Approved->value
                            : OvertimeStatus::Requested->value,
                    ]);
                }
            }

            $lateAttendance = AttendanceRecord::query()
                ->where('employee_id', $employee->id)
                ->where('status', AttendanceStatus::Late->value)
                ->orderByDesc('attendance_date')
                ->first();

            if ($lateAttendance && ! PenaltyRecord::query()
                ->where('employee_id', $employee->id)
                ->where('attendance_id', $lateAttendance->id)
                ->exists()) {
                PenaltyRecord::query()->create([
                    'employee_id' => $employee->id,
                    'penalty_rule_id' => $lateRule->id,
                    'attendance_id' => $lateAttendance->id,
                    'source' => PenaltySource::System->value,
                    'violation_type' => PenaltyViolationType::Late->value,
                    'original_points' => (float) $lateRule->points,
                    'adjusted_points' => null,
                    'final_points' => (float) $lateRule->points,
                    'reason' => 'Demo late penalty.',
                    'status' => PenaltyStatus::Applied->value,
                    'occurred_at' => $lateAttendance->attendance_date->setTime(9, 30),
                    'idempotency_key' => 'dev-penalty-late-'.$lateAttendance->id,
                ]);
            }

            $absentAttendance = AttendanceRecord::query()
                ->where('employee_id', $employee->id)
                ->where('status', AttendanceStatus::Absent->value)
                ->orderByDesc('attendance_date')
                ->first();

            if ($absentAttendance && ! PenaltyRecord::query()
                ->where('employee_id', $employee->id)
                ->where('attendance_id', $absentAttendance->id)
                ->exists()) {
                PenaltyRecord::query()->create([
                    'employee_id' => $employee->id,
                    'penalty_rule_id' => $absenceRule->id,
                    'attendance_id' => $absentAttendance->id,
                    'source' => PenaltySource::System->value,
                    'violation_type' => PenaltyViolationType::Absence->value,
                    'original_points' => (float) $absenceRule->points,
                    'adjusted_points' => null,
                    'final_points' => (float) $absenceRule->points,
                    'reason' => 'Demo absence penalty.',
                    'status' => PenaltyStatus::Applied->value,
                    'occurred_at' => $absentAttendance->attendance_date->setTime(12, 0),
                    'idempotency_key' => 'dev-penalty-absent-'.$absentAttendance->id,
                ]);
            }

            MonthlyRecap::query()->firstOrCreate(
                [
                    'employee_id' => $employee->id,
                    'period' => $period,
                ],
                [
                    'status' => match ($index % 4) {
                        0 => 'draft',
                        1 => 'reviewed',
                        2 => 'finalized',
                        default => 'exported',
                    },
                    'summary' => [
                        'present_days' => 18,
                        'late_days' => 2,
                        'absent_days' => 1,
                        'leave_days' => 1,
                        'overtime_minutes' => 120 + ($index * 30),
                        'penalty_points' => 5.0 * ($index % 3),
                    ],
                    'finalized_at' => $index % 4 >= 2 ? now()->subDays(2) : null,
                    'exported_at' => $index % 4 === 3 ? now()->subDay() : null,
                ],
            );
        }

        unset($context);
    }

    /**
     * Seeds a small sample of outsource attendance history for dev/testing.
     * Uses existing outsource records imported via OutsourceMasterDataImportService.
     * Skips silently if no outsource data has been imported yet.
     *
     * @param array{hq: WorkLocation, stores: list<WorkLocation>} $context
     */
    protected function seedOutsourceAttendance(array $context): void
    {
        // Pick up to 3 outsource workers that have at least one active store assignment.
        $outsources = Outsource::query()
            ->whereHas('stores', fn ($q) => $q->where('outsource_store_assignments.status', 'active'))
            ->where('status', 'active')
            ->orderBy('id')
            ->limit(3)
            ->get();

        if ($outsources->isEmpty()) {
            // No imported outsource data yet — skip silently.
            return;
        }

        $today = CarbonImmutable::today();

        foreach ($outsources as $index => $outsource) {
            // Use the outsource worker's actual first active store assignment,
            // falling back to the dev HQ location if none found.
            /** @var WorkLocation|null $store */
            $store = $outsource->stores()
                ->wherePivot('status', 'active')
                ->first();

            $location = $store ?? $context['hq'];

            for ($day = 7; $day >= 0; $day--) {
                $date = $today->subDays($day);

                if ($date->isWeekend()) {
                    continue;
                }

                if (AttendanceRecord::query()
                    ->where('outsource_id', $outsource->id)
                    ->whereDate('attendance_date', $date->toDateString())
                    ->exists()) {
                    continue;
                }

                $isIncomplete = $day === 0 && $index === 1;
                $checkInAt    = $date->setTime(8, 30);
                $checkOutAt   = $isIncomplete ? null : $date->setTime(17, 0);

                $record = AttendanceRecord::query()->create([
                    'employee_id'    => null,
                    'outsource_id'   => $outsource->id,
                    'attendable_type' => 'outsource',
                    'attendance_date' => $date->toDateString(),
                    'status'          => $isIncomplete
                        ? AttendanceStatus::Incomplete->value
                        : AttendanceStatus::Present->value,
                ]);

                $session = AttendanceSession::factory()
                    ->forRecord($record)
                    ->state([
                        'check_in_at'       => $checkInAt,
                        'check_out_at'      => $checkOutAt,
                        'duration_minutes'  => $isIncomplete ? null : 510,
                        'status'            => $isIncomplete
                            ? AttendanceSessionStatus::Open->value
                            : AttendanceSessionStatus::Closed->value,
                    ])
                    ->create();

                AttendanceEvent::factory()
                    ->checkIn()
                    ->forSession($session)
                    ->state([
                        'attendance_id' => $record->id,
                        'employee_id'   => null,
                        'outsource_id'  => $outsource->id,
                        'occurred_at'   => $checkInAt,
                        'latitude'      => $location->latitude,
                        'longitude'     => $location->longitude,
                    ])
                    ->create();

                if (! $isIncomplete && $checkOutAt !== null) {
                    AttendanceEvent::factory()
                        ->checkOut()
                        ->forSession($session)
                        ->state([
                            'attendance_id' => $record->id,
                            'employee_id'   => null,
                            'outsource_id'  => $outsource->id,
                            'occurred_at'   => $checkOutAt,
                            'latitude'      => $location->latitude,
                            'longitude'     => $location->longitude,
                        ])
                        ->create();
                }
            }
        }
    }
}
