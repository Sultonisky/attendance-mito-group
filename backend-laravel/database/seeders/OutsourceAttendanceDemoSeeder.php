<?php

namespace Database\Seeders;

use App\Enums\AttendanceSessionStatus;
use App\Enums\AttendanceStatus;
use App\Models\AttendanceEvent;
use App\Models\AttendanceRecord;
use App\Models\AttendanceSession;
use App\Models\City;
use App\Models\Outsource;
use App\Models\OutsourceStoreAssignment;
use App\Models\WorkLocation;
use App\Models\WorkLocationPin;
use Carbon\CarbonImmutable;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

/**
 * LOCAL ONLY.
 *
 * Seeds demo outsource attendance rows with pin address + coordinates,
 * including overnight (cross-midnight) and incomplete samples for the
 * outsource attendance report / CSV export.
 *
 * Safe to re-run: foundation anchors use firstOrCreate; attendance rows
 * skip when the same outsource + date already exists.
 */
class OutsourceAttendanceDemoSeeder extends Seeder
{
    use WithoutModelEvents;

    private const CITY_CODE = 'CITY-DEV-OUT-JKT';

    private const STORE_CODE = 'LOC-DEV-OUT-JKT';

    /** @var list<array{code: string, name: string}> */
    private const OUTSOURCES = [
        ['code' => '901', 'name' => 'Demo Outsource Budi'],
        ['code' => '902', 'name' => 'Demo Outsource Sari'],
        ['code' => '903', 'name' => 'Demo Outsource Andi'],
    ];

    public function run(): void
    {
        if (! app()->environment('local')) {
            $this->command?->warn('OutsourceAttendanceDemoSeeder skipped: local environment only.');

            return;
        }

        $store = $this->ensureStoreWithPins();
        $pins = $store->activePins()->orderBy('id')->get();
        $primaryPin = $pins->first();
        $secondaryPin = $pins->get(1) ?? $primaryPin;

        if ($primaryPin === null) {
            $this->command?->warn('OutsourceAttendanceDemoSeeder skipped: no active pins on demo store.');

            return;
        }

        $outsources = $this->ensureOutsources($store);
        $this->refreshDemoAttendance($outsources);

        $today = CarbonImmutable::now('Asia/Jakarta')->startOfDay();

        foreach ($outsources as $index => $outsource) {
            $this->seedWorkerHistory($outsource, $today, $primaryPin, $secondaryPin, $index);
        }

        $this->command?->info(sprintf(
            'Outsource attendance demo ready: %d workers on %s (%d pins).',
            $outsources->count(),
            $store->name,
            $pins->count(),
        ));
    }

    /**
     * @param  \Illuminate\Support\Collection<int, Outsource>  $outsources
     */
    protected function refreshDemoAttendance($outsources): void
    {
        $outsourceIds = $outsources->pluck('id');
        if ($outsourceIds->isEmpty()) {
            return;
        }

        $recordIds = AttendanceRecord::query()
            ->whereIn('outsource_id', $outsourceIds)
            ->pluck('id');

        if ($recordIds->isEmpty()) {
            return;
        }

        AttendanceEvent::query()->whereIn('attendance_id', $recordIds)->delete();
        AttendanceSession::query()->whereIn('attendance_record_id', $recordIds)->delete();
        AttendanceRecord::query()->whereIn('id', $recordIds)->delete();
    }

    /**
     * Wall-clock instant in attendance business timezone, stored as UTC.
     * App timezone is UTC; passing Asia/Jakarta Carbon without ->utc() makes
     * Eloquent persist the wall clock as UTC and shifts display by +7h.
     */
    protected function atJakarta(string $date, string $time): CarbonImmutable
    {
        return CarbonImmutable::createFromFormat(
            'Y-m-d H:i:s',
            "{$date} {$time}",
            (string) config('attendance.timezone', 'Asia/Jakarta'),
        )->utc();
    }

    protected function ensureStoreWithPins(): WorkLocation
    {
        $city = City::firstOrCreate(
            ['code' => self::CITY_CODE],
            ['name' => 'Jakarta (Demo Outsource)', 'status' => 'active'],
        );

        $store = WorkLocation::firstOrCreate(
            ['code' => self::STORE_CODE],
            [
                'city_id' => $city->id,
                'name' => 'Cabang Demo Jakarta Outsource',
                'latitude' => -6.200000,
                'longitude' => 106.816666,
                'radius_meters' => 150,
                'status' => 'active',
            ],
        );

        if ($store->city_id !== $city->id) {
            $store->forceFill(['city_id' => $city->id])->save();
        }

        WorkLocationPin::firstOrCreate(
            [
                'work_location_id' => $store->id,
                'name' => 'Pin Lobby Demo',
            ],
            [
                'address' => 'Jl. Sudirman No. 1, Jakarta Pusat',
                'latitude' => -6.200123,
                'longitude' => 106.816456,
                'radius_meters' => 150,
                'status' => 'active',
            ],
        );

        WorkLocationPin::firstOrCreate(
            [
                'work_location_id' => $store->id,
                'name' => 'Pin Loading Dock Demo',
            ],
            [
                'address' => 'Jl. Sudirman No. 1 Blok B, Jakarta Pusat',
                'latitude' => -6.201050,
                'longitude' => 106.817200,
                'radius_meters' => 120,
                'status' => 'active',
            ],
        );

        return $store->fresh(['activePins']);
    }

    /**
     * @return \Illuminate\Support\Collection<int, Outsource>
     */
    protected function ensureOutsources(WorkLocation $store)
    {
        $outsources = collect();

        foreach (self::OUTSOURCES as $row) {
            $outsource = Outsource::firstOrCreate(
                ['outsource_code' => $row['code']],
                [
                    'name' => $row['name'],
                    'password' => Outsource::DEFAULT_LOGIN_PIN,
                    'status' => 'active',
                ],
            );

            OutsourceStoreAssignment::firstOrCreate(
                [
                    'outsource_id' => $outsource->id,
                    'store_id' => $store->id,
                ],
                ['status' => 'active'],
            );

            $outsources->push($outsource);
        }

        return $outsources;
    }

    protected function seedWorkerHistory(
        Outsource $outsource,
        CarbonImmutable $today,
        WorkLocationPin $primaryPin,
        WorkLocationPin $secondaryPin,
        int $workerIndex,
    ): void {
        for ($day = 10; $day >= 0; $day--) {
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

            // Worker 0: overnight shift two weekdays ago (in 21:00 → out next day 07:00).
            $isOvernight = $workerIndex === 0 && $day === 2;
            // Worker 1: open session today (incomplete).
            $isIncomplete = $workerIndex === 1 && $day === 0;
            // Alternate pin for variety.
            $pin = ($day % 2 === 0) ? $primaryPin : $secondaryPin;
            $dateKey = $date->toDateString();

            if ($isOvernight) {
                $this->seedOvernightDay($outsource, $dateKey, $pin);

                continue;
            }

            $checkInAt = $this->atJakarta($dateKey, '08:30:00');
            $checkOutAt = $isIncomplete ? null : $this->atJakarta($dateKey, '17:00:00');

            $this->seedDay(
                outsource: $outsource,
                attendanceDate: $dateKey,
                pin: $pin,
                checkInAt: $checkInAt,
                checkOutAt: $checkOutAt,
                durationMinutes: $isIncomplete ? null : 510,
                incomplete: $isIncomplete,
            );
        }
    }

    protected function seedOvernightDay(
        Outsource $outsource,
        string $attendanceDate,
        WorkLocationPin $pin,
    ): void {
        $checkInAt = $this->atJakarta($attendanceDate, '21:00:00');
        $checkOutAt = $this->atJakarta($attendanceDate, '07:00:00')->addDay();

        $this->seedDay(
            outsource: $outsource,
            attendanceDate: $attendanceDate,
            pin: $pin,
            checkInAt: $checkInAt,
            checkOutAt: $checkOutAt,
            durationMinutes: 600,
            incomplete: false,
        );
    }

    protected function seedDay(
        Outsource $outsource,
        string $attendanceDate,
        WorkLocationPin $pin,
        CarbonImmutable $checkInAt,
        ?CarbonImmutable $checkOutAt,
        ?int $durationMinutes,
        bool $incomplete,
    ): void {
        $record = AttendanceRecord::query()->create([
            'employee_id' => null,
            'outsource_id' => $outsource->id,
            'attendable_type' => 'outsource',
            'attendance_date' => $attendanceDate,
            'status' => $incomplete
                ? AttendanceStatus::Incomplete->value
                : AttendanceStatus::Present->value,
        ]);

        $session = AttendanceSession::factory()
            ->forRecord($record)
            ->state([
                'check_in_at' => $checkInAt,
                'check_out_at' => $checkOutAt,
                'duration_minutes' => $durationMinutes,
                'status' => $incomplete
                    ? AttendanceSessionStatus::Open->value
                    : AttendanceSessionStatus::Closed->value,
            ])
            ->create();

        AttendanceEvent::factory()
            ->checkIn()
            ->forSession($session)
            ->state([
                'attendance_id' => $record->id,
                'employee_id' => null,
                'outsource_id' => $outsource->id,
                'work_location_pin_id' => $pin->id,
                'occurred_at' => $checkInAt,
                'latitude' => $pin->latitude,
                'longitude' => $pin->longitude,
                'source' => 'web',
            ])
            ->create();

        if (! $incomplete && $checkOutAt !== null) {
            AttendanceEvent::factory()
                ->checkOut()
                ->forSession($session)
                ->state([
                    'attendance_id' => $record->id,
                    'employee_id' => null,
                    'outsource_id' => $outsource->id,
                    'work_location_pin_id' => $pin->id,
                    'occurred_at' => $checkOutAt,
                    'latitude' => $pin->latitude,
                    'longitude' => $pin->longitude,
                    'source' => 'web',
                ])
                ->create();
        }
    }
}
