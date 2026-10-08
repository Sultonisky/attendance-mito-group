<?php

namespace Tests\Feature\Outsource;

use App\Models\AttendanceEvent;
use App\Models\AttendanceRecord;
use App\Models\AttendanceSession;
use App\Models\Outsource;
use App\Models\OutsourceStoreAssignment;
use App\Models\WorkLocation;
use App\Models\WorkLocationPin;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OutsourceHistoryPeriodApiTest extends TestCase
{
    use RefreshDatabase;

    private const DEVICE_FINGERPRINT = 'testdevicefingerprint01';

    private WorkLocationPin $pin;

    protected function setUp(): void
    {
        parent::setUp();
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-09-30 10:00', 'Asia/Jakarta'));
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    private function cookieName(): string
    {
        return (string) config('outsource_session.cookie.name', 'outsource_session');
    }

    private function createAssignedOutsource(): Outsource
    {
        $store = WorkLocation::factory()->create(['status' => 'active']);
        $this->pin = WorkLocationPin::factory()->forLocation($store)->create([
            'name' => 'Gate A',
            'status' => 'active',
        ]);
        $outsource = Outsource::factory()->create([
            'status' => 'active',
            'password' => '123456',
        ]);
        OutsourceStoreAssignment::factory()
            ->forOutsource($outsource)
            ->forStore($store)
            ->create(['status' => 'active']);

        return $outsource;
    }

    private function loginCookie(Outsource $outsource): string
    {
        $login = $this->postJson('/api/v1/outsource/login', [
            'outsource_code' => $outsource->outsource_code,
            'password' => '123456',
            'device_fingerprint' => self::DEVICE_FINGERPRINT,
        ]);
        $login->assertStatus(201);

        $cookie = $login->getCookie($this->cookieName(), false);
        $this->assertNotNull($cookie);

        return $cookie->getValue();
    }

    private function createRecord(Outsource $outsource, string $date, bool $closed = true): AttendanceRecord
    {
        $record = AttendanceRecord::factory()->create([
            'employee_id' => null,
            'outsource_id' => $outsource->id,
            'attendable_type' => 'outsource',
            'attendance_date' => $date,
            'status' => $closed ? 'present' : 'incomplete',
        ]);

        $factory = AttendanceSession::factory()->forRecord($record);
        $session = ($closed ? $factory->closed() : $factory)->create([
            'check_in_at' => "{$date} 01:00:00",
            'check_out_at' => $closed ? "{$date} 09:00:00" : null,
            'duration_minutes' => $closed ? 480 : null,
        ]);

        AttendanceEvent::factory()->checkIn()->forSession($session)->create([
            'employee_id' => null,
            'outsource_id' => $outsource->id,
            'attendance_id' => $record->id,
            'work_location_pin_id' => $this->pin->id,
            'occurred_at' => "{$date} 01:00:00",
        ]);

        return $record;
    }

    private function getPeriod(string $cookie, ?string $period = null)
    {
        $query = $period !== null ? "?period={$period}" : '';

        return $this->withCredentials()
            ->withUnencryptedCookie($this->cookieName(), $cookie)
            ->getJson("/api/v1/outsource/attendance/history/period{$query}");
    }

    public function test_lists_every_day_of_the_25th_to_24th_period(): void
    {
        $outsource = $this->createAssignedOutsource();
        $this->createRecord($outsource, '2026-08-24');
        $first = $this->createRecord($outsource, '2026-08-25');
        $last = $this->createRecord($outsource, '2026-09-24', closed: false);
        $this->createRecord($outsource, '2026-09-25');

        $response = $this->getPeriod($this->loginCookie($outsource), '2026-09');

        $response->assertOk();
        $response->assertJsonPath('data.period.key', '2026-09');
        $response->assertJsonPath('data.period.start_date', '2026-08-25');
        $response->assertJsonPath('data.period.end_date', '2026-09-24');
        $response->assertJsonPath('data.period.is_current', false);
        $response->assertJsonPath('data.period.previous_key', null);
        $response->assertJsonPath('data.period.next_key', '2026-10');
        $response->assertJsonCount(31, 'data.items');
        $response->assertJsonPath('data.items.0.attendance_date', '2026-09-24');
        $response->assertJsonPath('data.items.0.attendance_id', $last->id);
        $response->assertJsonPath('data.items.0.attended', true);
        $response->assertJsonPath('data.items.1.attendance_date', '2026-09-23');
        $response->assertJsonPath('data.items.1.attendance_id', null);
        $response->assertJsonPath('data.items.1.status', 'absent');
        $response->assertJsonPath('data.items.1.attended', false);
        $response->assertJsonPath('data.items.1.sessions', []);
        $response->assertJsonPath('data.items.30.attendance_date', '2026-08-25');
        $response->assertJsonPath('data.items.30.attendance_id', $first->id);
        $response->assertJsonPath('data.items.30.sessions.0.check_in_location.pin_name', 'Gate A');
        $response->assertJsonPath('data.items.30.sessions.0.check_out_location', null);
        $response->assertJsonPath('data.items.30.sessions.0.crosses_midnight', false);
        $response->assertJsonPath('data.summary.days_listed', 31);
        $response->assertJsonPath('data.summary.days_attended', 2);
        $response->assertJsonPath('data.summary.days_absent', 29);
        $response->assertJsonPath('data.summary.days_complete', 1);
        $response->assertJsonPath('data.summary.days_incomplete', 1);
        $response->assertJsonPath('data.summary.total_duration_minutes', 480);
        $response->assertJsonMissingPath('data.summary.max_days');
    }

    public function test_attended_days_are_not_limited_by_the_recap_quota(): void
    {
        config(['attendance.outsource_period_max_attendance_days' => 3]);

        $outsource = $this->createAssignedOutsource();
        AttendanceRecord::factory()->create([
            'employee_id' => null,
            'outsource_id' => $outsource->id,
            'attendable_type' => 'outsource',
            'attendance_date' => '2026-08-26',
            'status' => 'absent',
        ]);
        $this->createRecord($outsource, '2026-08-27');
        $this->createRecord($outsource, '2026-08-28', closed: false);
        $this->createRecord($outsource, '2026-09-01');
        $this->createRecord($outsource, '2026-09-02');
        $latest = $this->createRecord($outsource, '2026-09-10');

        $response = $this->getPeriod($this->loginCookie($outsource), '2026-09');

        $response->assertOk();
        $response->assertJsonCount(31, 'data.items');
        $response->assertJsonPath('data.items.14.attendance_date', '2026-09-10');
        $response->assertJsonPath('data.items.14.attendance_id', $latest->id);
        $response->assertJsonPath('data.items.29.attendance_date', '2026-08-26');
        $response->assertJsonPath('data.items.29.attendance_id', null);
        $response->assertJsonPath('data.items.29.status', 'absent');
        $response->assertJsonPath('data.summary.days_attended', 5);
        $response->assertJsonPath('data.summary.days_absent', 26);
        $response->assertJsonPath('data.summary.days_incomplete', 1);
        $response->assertJsonPath('data.summary.total_sessions', 5);
        $response->assertJsonPath('data.summary.total_duration_minutes', 1920);
    }

    public function test_cross_midnight_session_stays_in_clock_in_period_with_locations(): void
    {
        $outsource = $this->createAssignedOutsource();
        $outPin = WorkLocationPin::factory()->forLocation($this->pin->workLocation)->create([
            'name' => 'Gate B',
            'address' => 'Jl. Merdeka 2',
            'status' => 'active',
        ]);

        $record = AttendanceRecord::factory()->create([
            'employee_id' => null,
            'outsource_id' => $outsource->id,
            'attendable_type' => 'outsource',
            'attendance_date' => '2026-09-24',
            'status' => 'present',
        ]);
        // 22:00 WIB on 24 Sep -> 06:10 WIB on 25 Sep (stored in UTC).
        $session = AttendanceSession::factory()->closed()->forRecord($record)->create([
            'check_in_at' => '2026-09-24 15:00:00',
            'check_out_at' => '2026-09-24 23:10:00',
            'duration_minutes' => 490,
        ]);
        AttendanceEvent::factory()->checkIn()->forSession($session)->create([
            'employee_id' => null,
            'outsource_id' => $outsource->id,
            'attendance_id' => $record->id,
            'work_location_pin_id' => $this->pin->id,
            'occurred_at' => '2026-09-24 15:00:00',
            'latitude' => -6.2,
            'longitude' => 106.8,
            'accuracy_meters' => 12.34,
        ]);
        AttendanceEvent::factory()->checkOut()->forSession($session)->create([
            'employee_id' => null,
            'outsource_id' => $outsource->id,
            'attendance_id' => $record->id,
            'work_location_pin_id' => $outPin->id,
            'occurred_at' => '2026-09-24 23:10:00',
            'latitude' => -6.21,
            'longitude' => 106.81,
            'accuracy_meters' => 8,
        ]);

        $cookie = $this->loginCookie($outsource);

        $response = $this->getPeriod($cookie, '2026-09');
        $response->assertOk();
        $response->assertJsonCount(31, 'data.items');
        $response->assertJsonPath('data.summary.days_attended', 1);
        $response->assertJsonPath('data.items.0.attendance_date', '2026-09-24');
        $response->assertJsonPath('data.items.0.check_out_date', '2026-09-25');
        $response->assertJsonPath('data.items.0.check_out_day_offset', 1);
        $response->assertJsonPath('data.items.0.duration_minutes', 490);
        $response->assertJsonPath('data.items.0.sessions.0.check_in_date', '2026-09-24');
        $response->assertJsonPath('data.items.0.sessions.0.check_out_date', '2026-09-25');
        $response->assertJsonPath('data.items.0.sessions.0.crosses_midnight', true);
        $response->assertJsonPath('data.items.0.sessions.0.check_in_location.pin_name', 'Gate A');
        $response->assertJsonMissingPath('data.items.0.sessions.0.check_in_location.latitude');
        $response->assertJsonMissingPath('data.items.0.sessions.0.check_in_location.accuracy_meters');
        $response->assertJsonPath('data.items.0.sessions.0.check_out_location.pin_name', 'Gate B');
        $response->assertJsonPath('data.items.0.sessions.0.check_out_location.pin_address', 'Jl. Merdeka 2');
        $response->assertJsonMissingPath('data.items.0.sessions.0.check_out_location.longitude');
        $response->assertJsonPath('data.summary.days_cross_midnight', 1);
        $response->assertJsonPath('data.summary.total_duration_minutes', 490);

        $this->getPeriod($cookie, '2026-10')
            ->assertOk()
            ->assertJsonPath('data.summary.days_attended', 0);
    }

    public function test_current_period_lists_days_up_to_today_only(): void
    {
        $outsource = $this->createAssignedOutsource();
        $record = $this->createRecord($outsource, '2026-09-29');

        $response = $this->getPeriod($this->loginCookie($outsource));

        $response->assertOk();
        $response->assertJsonPath('data.period.key', '2026-10');
        $response->assertJsonPath('data.period.start_date', '2026-09-25');
        $response->assertJsonPath('data.period.end_date', '2026-10-24');
        $response->assertJsonPath('data.period.is_current', true);
        $response->assertJsonPath('data.period.next_key', null);
        $response->assertJsonPath('data.period.previous_key', '2026-09');
        // 25..30 Sep; today (30 Sep, no clock-in yet) is pending, not absent.
        $response->assertJsonCount(6, 'data.items');
        $response->assertJsonPath('data.items.0.attendance_date', '2026-09-30');
        $response->assertJsonPath('data.items.0.status', 'pending');
        $response->assertJsonPath('data.items.1.attendance_id', $record->id);
        $response->assertJsonPath('data.items.5.attendance_date', '2026-09-25');
        $response->assertJsonPath('data.items.5.status', 'absent');
        $response->assertJsonPath('data.summary.days_attended', 1);
        $response->assertJsonPath('data.summary.days_absent', 4);
    }

    public function test_rejects_periods_before_first_period(): void
    {
        $cookie = $this->loginCookie($this->createAssignedOutsource());

        $this->getPeriod($cookie, '2026-08')->assertStatus(422)->assertJsonPath('code', 'INVALID_PERIOD');
        $this->getPeriod($cookie, '2025-12')->assertStatus(422)->assertJsonPath('code', 'INVALID_PERIOD');
    }

    public function test_does_not_include_other_outsource_records(): void
    {
        $outsource = $this->createAssignedOutsource();
        $other = Outsource::factory()->create(['status' => 'active']);
        $this->createRecord($other, '2026-09-01');

        $response = $this->getPeriod($this->loginCookie($outsource), '2026-09');

        $response->assertOk();
        $response->assertJsonCount(31, 'data.items');
        $response->assertJsonPath('data.summary.days_attended', 0);
        $response->assertJsonPath('data.summary.days_absent', 31);
    }

    public function test_rejects_invalid_and_future_periods(): void
    {
        $cookie = $this->loginCookie($this->createAssignedOutsource());

        $this->getPeriod($cookie, '2026-13')->assertStatus(422)->assertJsonPath('code', 'INVALID_PERIOD');
        $this->getPeriod($cookie, '2026-11')->assertStatus(422)->assertJsonPath('code', 'INVALID_PERIOD');
    }

    public function test_requires_outsource_session(): void
    {
        $this->getJson('/api/v1/outsource/attendance/history/period')
            ->assertStatus(401)
            ->assertJsonPath('code', 'INVALID_SESSION');
    }
}
