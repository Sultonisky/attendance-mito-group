<?php

namespace Database\Seeders;

use App\Enums\AttendanceSessionStatus;
use App\Enums\AttendanceStatus;
use App\Enums\RecordStatus;
use App\Models\AttendanceEvent;
use App\Models\AttendanceRecord;
use App\Models\AttendanceSession;
use App\Models\City;
use App\Models\Employee;
use App\Models\LeaveBalance;
use App\Models\LeaveType;
use App\Models\Outsource;
use App\Models\OutsourceStoreAssignment;
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
 * The seeder is idempotent: the whole run is skipped when demo employees
 * already exist, and deterministic anchors (HQ location, schedule, policy,
 * leave types, penalty rules) use firstOrCreate so partial re-runs are safe.
 */
class DevelopmentDataSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Prefix for every factory-generated demo employee code.
     */
    protected const EMPLOYEE_PREFIX = 'EMP-DEV';

    /**
     * Number of demo employees with a linked user account (login enabled).
     */
    protected const LINKED_EMPLOYEES = 4;

    /**
     * Number of extra demo employees without a user account.
     */
    protected const UNLINKED_EMPLOYEES = 6;

    public function run(): void
    {
        if (Employee::where('employee_code', 'like', self::EMPLOYEE_PREFIX.'%')->exists()) {
            return; // Already seeded.
        }

        $context = $this->seedFoundationData();
        $this->seedLeaveTypesAndPenaltyRules();
        $this->seedDemoEmployees($context);
        $this->seedAttendanceHistory($context);
    }

    /**
     * Shared reference data: cities, stores, schedule, policy.
     *
     * @return array{hq: WorkLocation, stores: list<WorkLocation>, schedule: WorkSchedule, shift: Shift, policy: Policy}
     */
    protected function seedFoundationData(): array
    {
        // City anchor (deterministic) plus a couple of factory cities.
        City::firstOrCreate(
            ['code' => 'CITY-DEV-JKT'],
            ['name' => 'Jakarta', 'status' => 'active'],
        );

        City::factory()->count(2)->create();

        // HQ anchor with real coordinates so geofence testing works.
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

        $stores = WorkLocation::factory()->count(3)->create();

        // Standard office schedule with a 09:00-18:00 shift, open-ended.
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

        // Open-ended, non-blocking attendance policy.
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

    /**
     * Leave types + penalty rules (deterministic anchors).
     */
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
     * Create demo employees via factories, wire schedule + policy
     * assignments, annual leave balances, and (for the first few) linked
     * login accounts.
     *
     * @param array{hq: WorkLocation, stores: list<WorkLocation>, schedule: WorkSchedule, policy: Policy} $context
     */
    protected function seedDemoEmployees(array $context): void
    {
        $userRole = Role::where('name', 'USER')->first();

        // Employees with a working user account (password: "password").
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

        // Extra employees without login accounts.
        $unlinked = Employee::factory()
            ->count(self::UNLINKED_EMPLOYEES)
            ->create();

        $employees = $linked->merge($unlinked);

        foreach ($employees as $index => $employee) {
            $employee->forceFill([
                'employee_code' => self::EMPLOYEE_PREFIX.'-'.str_pad((string) ($index + 1), 3, '0', STR_PAD_LEFT),
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

        // Outsource demo data: 3 workers assigned to random stores.
        $outsources = Outsource::factory()->count(3)->create();

        foreach ($outsources as $index => $outsource) {
            $store = $context['stores'][$index % count($context['stores'])];

            OutsourceStoreAssignment::factory()
                ->forOutsource($outsource)
                ->forStore($store)
                ->create();
        }
    }

    /**
     * Seed two weeks of weekday attendance history for every demo employee
     * so reports/dashboards have data to render.
     *
     * @param array{hq: WorkLocation} $context
     */
    protected function seedAttendanceHistory(array $context): void
    {
        $employees = Employee::where('employee_code', 'like', self::EMPLOYEE_PREFIX.'%')->get();
        $today = CarbonImmutable::today();

        foreach ($employees as $employee) {
            for ($day = 12; $day >= 1; $day--) {
                $date = $today->subDays($day);

                if ($date->isWeekend()) {
                    continue;
                }

                $this->seedAttendanceDay($employee, $date, $context['hq']);
            }
        }
    }

    /**
     * Create one "present" attendance day: record + closed session +
     * check-in/check-out events around the HQ geofence.
     */
    protected function seedAttendanceDay(Employee $employee, CarbonImmutable $date, WorkLocation $hq): void
    {
        $checkInAt = $date->setTime(8, 55);
        $checkOutAt = $date->setTime(18, 5);

        $record = AttendanceRecord::factory()
            ->forEmployee($employee)
            ->onDate($date->toDateString())
            ->status(AttendanceStatus::Present->value)
            ->create();

        $session = AttendanceSession::factory()
            ->forRecord($record)
            ->state([
                'check_in_at' => $checkInAt,
                'check_out_at' => $checkOutAt,
                'duration_minutes' => 550,
                'status' => AttendanceSessionStatus::Closed->value,
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
