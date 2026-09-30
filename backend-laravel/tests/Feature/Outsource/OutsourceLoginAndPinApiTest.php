<?php

namespace Tests\Feature\Outsource;

use App\Models\Outsource;
use App\Models\OutsourceStoreAssignment;
use App\Models\WorkLocation;
use App\Models\WorkLocationPin;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OutsourceLoginAndPinApiTest extends TestCase
{
    use RefreshDatabase;

    private const DEVICE_FINGERPRINT = 'testdevicefingerprint01';

    private function cookieName(): string
    {
        return (string) config('outsource_session.cookie.name', 'outsource_session');
    }

    public function test_login_mints_session_and_returns_allowed_pins(): void
    {
        $store = WorkLocation::factory()->create([
            'latitude' => -6.2,
            'longitude' => 106.8,
            'status' => 'active',
        ]);
        $pin = WorkLocationPin::factory()->forLocation($store)->atCoordinates(-6.2, 106.8)->create([
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

        $response = $this->postJson('/api/v1/outsource/login', [
            'outsource_code' => $outsource->outsource_code,
            'password' => '123456',
            'device_fingerprint' => self::DEVICE_FINGERPRINT,
        ]);

        $response->assertStatus(201);
        $response->assertJsonPath('data.outsource.id', $outsource->id);
        $response->assertJsonPath('data.store.id', $store->id);
        $response->assertJsonPath('data.can_clock_in', true);
        $response->assertJsonPath('data.pins.0.id', $pin->id);
        $this->assertNotNull($response->getCookie($this->cookieName(), false));
    }

    private function makeLoginableOutsource(): Outsource
    {
        $store = WorkLocation::factory()->create(['status' => 'active']);
        WorkLocationPin::factory()->forLocation($store)->create(['status' => 'active']);
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

    private function makeFinishedSession(Outsource $outsource, string $date, string $status): \App\Models\AttendanceRecord
    {
        $record = \App\Models\AttendanceRecord::factory()->create([
            'employee_id' => null,
            'outsource_id' => $outsource->id,
            'attendable_type' => 'outsource',
            'attendance_date' => $date,
            'status' => $status === 'closed' ? 'present' : 'incomplete',
        ]);

        \App\Models\AttendanceSession::factory()->forRecord($record)->create([
            'check_in_at' => "{$date} 01:00:00",
            'check_out_at' => $status === 'closed' ? "{$date} 02:00:00" : null,
            'duration_minutes' => $status === 'closed' ? 60 : null,
            'status' => $status,
        ]);

        return $record;
    }

    private function login(Outsource $outsource): \Illuminate\Testing\TestResponse
    {
        return $this->postJson('/api/v1/outsource/login', [
            'outsource_code' => $outsource->outsource_code,
            'password' => '123456',
            'device_fingerprint' => self::DEVICE_FINGERPRINT,
        ]);
    }

    public function test_login_returns_completed_when_today_attendance_is_closed(): void
    {
        $this->travelTo(\Carbon\CarbonImmutable::parse('2026-09-27 08:00:00', 'UTC'));
        $outsource = $this->makeLoginableOutsource();
        $record = $this->makeFinishedSession($outsource, '2026-09-27', 'closed');

        $response = $this->login($outsource);

        $response->assertStatus(201);
        $response->assertJsonPath('data.status', 'COMPLETED');
        $response->assertJsonPath('data.can_clock_in', false);
        $response->assertJsonPath('data.can_clock_out', false);
        $response->assertJsonPath('data.attendance.attendance_id', $record->id);
        $response->assertJsonPath('data.attendance.session_status', 'closed');
        $response->assertJsonPath('data.attendance.duration_minutes', 60);

        $cookie = $response->getCookie($this->cookieName(), false);
        $current = $this->withCredentials()
            ->withUnencryptedCookie($this->cookieName(), $cookie->getValue())
            ->getJson('/api/v1/outsource/session/current');

        $current->assertOk();
        $current->assertJsonPath('data.status', 'COMPLETED');
        $current->assertJsonPath('data.attendance.attendance_id', $record->id);
    }

    public function test_login_returns_completed_when_today_session_expired(): void
    {
        $this->travelTo(\Carbon\CarbonImmutable::parse('2026-09-27 08:00:00', 'UTC'));
        $outsource = $this->makeLoginableOutsource();
        $this->makeFinishedSession($outsource, '2026-09-27', 'expired');

        $response = $this->login($outsource);

        $response->assertStatus(201);
        $response->assertJsonPath('data.status', 'COMPLETED');
        $response->assertJsonPath('data.can_clock_in', false);
        $response->assertJsonPath('data.attendance.session_status', 'expired');
        $response->assertJsonPath('data.attendance.check_out_at', null);
    }

    public function test_login_returns_ready_when_only_previous_day_is_completed(): void
    {
        $this->travelTo(\Carbon\CarbonImmutable::parse('2026-09-27 08:00:00', 'UTC'));
        $outsource = $this->makeLoginableOutsource();
        $this->makeFinishedSession($outsource, '2026-09-26', 'closed');

        $response = $this->login($outsource);

        $response->assertStatus(201);
        $response->assertJsonPath('data.status', 'READY');
        $response->assertJsonPath('data.can_clock_in', true);
        $response->assertJsonPath('data.attendance', null);
    }

    public function test_login_rejects_bad_password(): void
    {
        $store = WorkLocation::factory()->create(['status' => 'active']);
        WorkLocationPin::factory()->forLocation($store)->create(['status' => 'active']);
        $outsource = Outsource::factory()->create([
            'status' => 'active',
            'password' => '123456',
        ]);
        OutsourceStoreAssignment::factory()
            ->forOutsource($outsource)
            ->forStore($store)
            ->create(['status' => 'active']);

        $response = $this->postJson('/api/v1/outsource/login', [
            'outsource_code' => $outsource->outsource_code,
            'password' => '999999',
            'device_fingerprint' => self::DEVICE_FINGERPRINT,
        ]);

        $response->assertStatus(422);
        $response->assertJsonPath('code', 'INVALID_CREDENTIALS');
    }

    public function test_login_rejects_inactive_outsource_with_specific_code(): void
    {
        $store = WorkLocation::factory()->create(['status' => 'active']);
        WorkLocationPin::factory()->forLocation($store)->create(['status' => 'active']);
        $outsource = Outsource::factory()->create([
            'status' => 'inactive',
            'password' => '123456',
        ]);
        OutsourceStoreAssignment::factory()
            ->forOutsource($outsource)
            ->forStore($store)
            ->create(['status' => 'active']);

        $response = $this->postJson('/api/v1/outsource/login', [
            'outsource_code' => $outsource->outsource_code,
            'password' => '123456',
            'device_fingerprint' => self::DEVICE_FINGERPRINT,
        ]);

        $response->assertStatus(422);
        $response->assertJsonPath('code', 'INACTIVE_OUTSOURCE');
        $response->assertJsonPath('message', 'Outsource is inactive. Contact your administrator.');
    }

    public function test_login_inactive_with_wrong_password_stays_generic(): void
    {
        $store = WorkLocation::factory()->create(['status' => 'active']);
        $outsource = Outsource::factory()->create([
            'status' => 'inactive',
            'password' => '123456',
        ]);
        OutsourceStoreAssignment::factory()
            ->forOutsource($outsource)
            ->forStore($store)
            ->create(['status' => 'active']);

        $response = $this->postJson('/api/v1/outsource/login', [
            'outsource_code' => $outsource->outsource_code,
            'password' => '999999',
            'device_fingerprint' => self::DEVICE_FINGERPRINT,
        ]);

        $response->assertStatus(422);
        $response->assertJsonPath('code', 'INVALID_CREDENTIALS');
    }

    public function test_attendance_history_returns_own_records(): void
    {
        $city = \App\Models\City::factory()->create([
            'name' => 'Bandung',
            'status' => 'active',
        ]);
        $store = WorkLocation::factory()->create([
            'city_id' => $city->id,
            'name' => 'Head Office',
            'status' => 'active',
        ]);
        WorkLocationPin::factory()->forLocation($store)->create(['status' => 'active']);
        $outsource = Outsource::factory()->create([
            'status' => 'active',
            'password' => '123456',
        ]);
        OutsourceStoreAssignment::factory()
            ->forOutsource($outsource)
            ->forStore($store)
            ->create(['status' => 'active']);

        $record = \App\Models\AttendanceRecord::factory()->create([
            'employee_id' => null,
            'outsource_id' => $outsource->id,
            'attendable_type' => 'outsource',
            'attendance_date' => '2026-09-20',
            'status' => 'present',
        ]);
        \App\Models\AttendanceSession::factory()->closed()->forRecord($record)->create([
            'check_in_at' => '2026-09-20 01:00:00',
            'check_out_at' => '2026-09-20 09:00:00',
            'duration_minutes' => 480,
        ]);

        $login = $this->postJson('/api/v1/outsource/login', [
            'outsource_code' => $outsource->outsource_code,
            'password' => '123456',
            'device_fingerprint' => self::DEVICE_FINGERPRINT,
        ]);
        $login->assertStatus(201);
        $login->assertJsonPath('data.city.name', 'Bandung');
        $login->assertJsonPath('data.store.city_name', 'Bandung');

        $cookie = $login->getCookie($this->cookieName(), false);
        $this->assertNotNull($cookie);

        $history = $this->withCredentials()
            ->withUnencryptedCookie($this->cookieName(), $cookie->getValue())
            ->getJson('/api/v1/outsource/attendance/history');

        $history->assertOk();
        $history->assertJsonPath('data.0.attendance_id', $record->id);
        $history->assertJsonPath('data.0.attendance_date', '2026-09-20');
        $history->assertJsonPath('data.0.duration_minutes', 480);
    }
}
