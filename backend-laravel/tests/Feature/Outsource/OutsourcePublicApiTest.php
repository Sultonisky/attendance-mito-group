<?php

namespace Tests\Feature\Outsource;

use App\Actions\Outsource\ResolveOutsourceSession;
use App\Enums\OutsourceAttendanceSessionStatus;
use App\Models\AttendanceRecord;
use App\Models\AttendanceSession;
use App\Models\City;
use App\Models\Outsource;
use App\Models\OutsourceStoreAssignment;
use App\Models\WorkLocation;
use App\Services\Outsource\Session\OutsourceSessionData;
use App\Services\Outsource\Session\OutsourceSessionStoreInterface;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OutsourcePublicApiTest extends TestCase
{
    use RefreshDatabase;

    private const DEVICE_FINGERPRINT = 'testdevicefingerprint01';

    private function cookieName(): string
    {
        return (string) config('outsource_session.cookie.name', 'outsource_session');
    }

    private function sessionStore(): OutsourceSessionStoreInterface
    {
        return $this->app->make(OutsourceSessionStoreInterface::class);
    }

    private function withOutsourceSession(string $sessionId): self
    {
        return $this->withCredentials()
            ->withUnencryptedCookie($this->cookieName(), $sessionId);
    }

    private function makeStore(float $lat, float $lng, float $radius = 150, ?int $cityId = null): WorkLocation
    {
        return WorkLocation::factory()->create([
            'latitude' => $lat,
            'longitude' => $lng,
            'radius_meters' => $radius,
            'city_id' => $cityId,
        ]);
    }

    private function makeActiveAssignment(Outsource $outsource, WorkLocation $store): OutsourceStoreAssignment
    {
        return OutsourceStoreAssignment::factory()
            ->forOutsource($outsource)
            ->forStore($store)
            ->create(['status' => 'active']);
    }

    private function putSession(
        Outsource $outsource,
        WorkLocation $store,
        string $sessionId = 'test-session-id-0123456789abcdef0123456789abcdef',
        ?CarbonImmutable $expiresAt = null,
        string $status = 'active',
        string $fingerprint = self::DEVICE_FINGERPRINT,
    ): OutsourceSessionData {
        $now = CarbonImmutable::now();
        $session = new OutsourceSessionData(
            id: $sessionId,
            outsourceId: $outsource->id,
            storeId: $store->id,
            status: $status,
            deviceFingerprint: $fingerprint,
            ipAddress: null,
            userAgent: null,
            createdAt: $now,
            expiresAt: $expiresAt ?? $now->addHours(12),
            lastUsedAt: $now,
        );

        $this->sessionStore()->put($session);

        return $session;
    }

    private function initPayload(City $city, WorkLocation $store, Outsource $outsource): array
    {
        return [
            'city_id' => $city->id,
            'store_id' => $store->id,
            'outsource_id' => $outsource->id,
            'device_fingerprint' => self::DEVICE_FINGERPRINT,
        ];
    }

    // ============================================================
    // DISCOVERY ENDPOINTS
    // ============================================================

    public function test_cities_endpoint_returns_only_active_cities(): void
    {
        City::factory()->create(['status' => 'active']);
        City::factory()->create(['status' => 'inactive']);

        $response = $this->getJson('/api/v1/outsource/cities');

        $response->assertStatus(200);
        $response->assertJson(['success' => true]);
        $response->assertJsonCount(1, 'data');
    }

    public function test_stores_endpoint_filters_by_city(): void
    {
        $city1 = City::factory()->create();
        $city2 = City::factory()->create();
        $store1 = WorkLocation::factory()->create(['city_id' => $city1->id]);
        WorkLocation::factory()->create(['city_id' => $city2->id]);

        $response = $this->getJson('/api/v1/outsource/stores?city_id='.$city1->id);

        $response->assertStatus(200);
        $response->assertJsonCount(1, 'data');
        $response->assertJsonPath('data.0.id', $store1->id);
    }

    public function test_outsources_endpoint_filters_by_store(): void
    {
        $outsource = Outsource::factory()->create();
        $store = WorkLocation::factory()->create();
        $this->makeActiveAssignment($outsource, $store);

        $response = $this->getJson('/api/v1/outsource/outsources?store_id='.$store->id);

        $response->assertStatus(200);
        $response->assertJsonCount(1, 'data');
        $response->assertJsonPath('data.0.id', $outsource->id);
    }

    public function test_stores_endpoint_includes_coordinates_when_available(): void
    {
        $city = City::factory()->create();
        $store = WorkLocation::factory()->create([
            'city_id' => $city->id,
            'latitude' => -6.2001,
            'longitude' => 106.8166,
        ]);

        $response = $this->getJson('/api/v1/outsource/stores?city_id='.$city->id);

        $response->assertStatus(200);
        $response->assertJsonPath('data.0.id', $store->id);
        $response->assertJsonPath('data.0.latitude', -6.2001);
        $response->assertJsonPath('data.0.longitude', 106.8166);
    }

    public function test_stores_endpoint_excludes_inactive_stores(): void
    {
        $city = City::factory()->create();
        WorkLocation::factory()->create(['city_id' => $city->id, 'status' => 'inactive']);

        $response = $this->getJson('/api/v1/outsource/stores?city_id='.$city->id);

        $response->assertStatus(200);
        $response->assertJsonCount(0, 'data');
    }

    public function test_outsources_endpoint_excludes_inactive_outsources(): void
    {
        $outsource = Outsource::factory()->create(['status' => 'inactive']);
        $store = WorkLocation::factory()->create();
        $this->makeActiveAssignment($outsource, $store);

        $response = $this->getJson('/api/v1/outsource/outsources?store_id='.$store->id);

        $response->assertStatus(200);
        $response->assertJsonCount(0, 'data');
    }

    // ============================================================
    // SESSION INIT
    // ============================================================

    public function test_valid_assignment_creates_session_cookie(): void
    {
        $city = City::factory()->create();
        $outsource = Outsource::factory()->create(['status' => 'active']);
        $store = $this->makeStore(-6.2, 106.8, 150, $city->id);
        $this->makeActiveAssignment($outsource, $store);

        $response = $this->postJson('/api/v1/outsource/session/init', $this->initPayload($city, $store, $outsource));

        $response->assertStatus(201);
        $response->assertJson(['success' => true]);
        $response->assertJsonPath('data.status', 'READY');
        $response->assertJsonMissingPath('data.session_token');
        $response->assertCookie($this->cookieName());
        $this->assertDatabaseCount('outsource_attendance_sessions', 0);
    }

    public function test_invalid_assignment_rejected(): void
    {
        $city = City::factory()->create();
        $outsource = Outsource::factory()->create(['status' => 'active']);
        $store = $this->makeStore(-6.2, 106.8, 150, $city->id);

        $response = $this->postJson('/api/v1/outsource/session/init', $this->initPayload($city, $store, $outsource));

        $response->assertStatus(422);
        $response->assertJson(['success' => false]);
    }

    public function test_inactive_outsource_rejected(): void
    {
        $city = City::factory()->create();
        $outsource = Outsource::factory()->create(['status' => 'inactive']);
        $store = $this->makeStore(-6.2, 106.8, 150, $city->id);

        $response = $this->postJson('/api/v1/outsource/session/init', $this->initPayload($city, $store, $outsource));

        $response->assertStatus(422);
    }

    public function test_inactive_store_rejected(): void
    {
        $city = City::factory()->create();
        $outsource = Outsource::factory()->create(['status' => 'active']);
        $store = $this->makeStore(-6.2, 106.8, 150, $city->id);
        $store->update(['status' => 'inactive']);

        $response = $this->postJson('/api/v1/outsource/session/init', $this->initPayload($city, $store, $outsource));

        $response->assertStatus(422);
    }

    public function test_store_city_mismatch_rejected(): void
    {
        $city1 = City::factory()->create();
        $city2 = City::factory()->create();
        $outsource = Outsource::factory()->create(['status' => 'active']);
        $store = $this->makeStore(-6.2, 106.8, 150, $city2->id);
        $this->makeActiveAssignment($outsource, $store);

        $response = $this->postJson('/api/v1/outsource/session/init', $this->initPayload($city1, $store, $outsource));

        $response->assertStatus(422);
    }

    public function test_session_id_not_exposed_in_json_body(): void
    {
        $city = City::factory()->create();
        $outsource = Outsource::factory()->create(['status' => 'active']);
        $store = $this->makeStore(-6.2, 106.8, 150, $city->id);
        $this->makeActiveAssignment($outsource, $store);

        $response = $this->postJson('/api/v1/outsource/session/init', $this->initPayload($city, $store, $outsource));

        $response->assertStatus(201);
        $this->assertNull($response->json('data.session_token'));
        $this->assertNotEmpty($response->getCookie($this->cookieName(), false)?->getValue());
    }

    public function test_reinit_revokes_previous_session_for_same_outsource(): void
    {
        $city = City::factory()->create();
        $outsource = Outsource::factory()->create(['status' => 'active']);
        $store = $this->makeStore(-6.2, 106.8, 150, $city->id);
        $this->makeActiveAssignment($outsource, $store);

        $first = $this->postJson('/api/v1/outsource/session/init', $this->initPayload($city, $store, $outsource));
        $firstId = $first->getCookie($this->cookieName(), false)?->getValue();

        $second = $this->postJson('/api/v1/outsource/session/init', $this->initPayload($city, $store, $outsource));
        $secondId = $second->getCookie($this->cookieName(), false)?->getValue();

        $this->assertNotEmpty($firstId);
        $this->assertNotEmpty($secondId);
        $this->assertNotEquals($firstId, $secondId);
        $this->assertNull($this->sessionStore()->find((string) $firstId));
        $this->assertNotNull($this->sessionStore()->find((string) $secondId));
    }

    public function test_device_with_open_attendance_cannot_init_session_for_another_outsource(): void
    {
        $city = City::factory()->create();
        $store = $this->makeStore(-6.2, 106.8, 150, $city->id);

        $outsourceA = Outsource::factory()->create(['status' => 'active']);
        $outsourceB = Outsource::factory()->create(['status' => 'active']);
        $this->makeActiveAssignment($outsourceA, $store);
        $this->makeActiveAssignment($outsourceB, $store);

        $initA = $this->postJson('/api/v1/outsource/session/init', $this->initPayload($city, $store, $outsourceA));
        $initA->assertStatus(201);
        $sessionId = (string) $initA->getCookie($this->cookieName(), false)?->getValue();

        $this->withOutsourceSession($sessionId)->postJson('/api/v1/outsource/attendance/check-in', [
            'latitude' => -6.2001,
            'longitude' => 106.8001,
            'accuracy_meters' => 10,
            'device_fingerprint' => self::DEVICE_FINGERPRINT,
        ])->assertStatus(201);

        $initB = $this->postJson('/api/v1/outsource/session/init', $this->initPayload($city, $store, $outsourceB));

        $initB->assertStatus(409);
        $initB->assertJson([
            'success' => false,
            'code' => 'DEVICE_BUSY',
        ]);
    }

    public function test_same_outsource_can_reinit_session_on_same_device_while_open(): void
    {
        $city = City::factory()->create();
        $store = $this->makeStore(-6.2, 106.8, 150, $city->id);
        $outsource = Outsource::factory()->create(['status' => 'active']);
        $this->makeActiveAssignment($outsource, $store);

        $initA = $this->postJson('/api/v1/outsource/session/init', $this->initPayload($city, $store, $outsource));
        $tokenA = (string) $initA->getCookie($this->cookieName(), false)?->getValue();

        $this->withOutsourceSession($tokenA)->postJson('/api/v1/outsource/attendance/check-in', [
            'latitude' => -6.2001,
            'longitude' => 106.8001,
            'accuracy_meters' => 10,
            'device_fingerprint' => self::DEVICE_FINGERPRINT,
        ])->assertStatus(201);

        $initAgain = $this->postJson('/api/v1/outsource/session/init', $this->initPayload($city, $store, $outsource));

        $initAgain->assertStatus(201);
        $this->assertNotEquals($tokenA, $initAgain->getCookie($this->cookieName(), false)?->getValue());
        $initAgain->assertJsonPath('data.status', 'ACTIVE');
        $this->assertNotEmpty($initAgain->json('data.attendance.check_in_at'));
    }

    public function test_current_session_returns_none_without_cookie(): void
    {
        $response = $this->getJson('/api/v1/outsource/session/current');

        $response->assertStatus(200);
        $response->assertJsonPath('data.status', 'NONE');
        $response->assertJsonPath('data.attendance', null);
    }

    public function test_current_session_restores_active_open_attendance(): void
    {
        $city = City::factory()->create();
        $store = $this->makeStore(-6.2, 106.8, 150, $city->id);
        $outsource = Outsource::factory()->create(['status' => 'active']);
        $this->makeActiveAssignment($outsource, $store);

        $init = $this->postJson('/api/v1/outsource/session/init', $this->initPayload($city, $store, $outsource));
        $sessionId = (string) $init->getCookie($this->cookieName(), false)?->getValue();

        $this->withOutsourceSession($sessionId)->postJson('/api/v1/outsource/attendance/check-in', [
            'latitude' => -6.2001,
            'longitude' => 106.8001,
            'accuracy_meters' => 10,
            'device_fingerprint' => self::DEVICE_FINGERPRINT,
        ])->assertStatus(201);

        $current = $this->withOutsourceSession($sessionId)->getJson('/api/v1/outsource/session/current');

        $current->assertStatus(200);
        $current->assertJsonPath('data.status', 'ACTIVE');
        $current->assertJsonPath('data.outsource.id', $outsource->id);
        $current->assertJsonPath('data.store.id', $store->id);
        $this->assertNotEmpty($current->json('data.attendance.check_in_at'));
    }

    // ============================================================
    // SESSION RESOLVER
    // ============================================================

    public function test_valid_session_resolves_correct_binding(): void
    {
        $outsource = Outsource::factory()->create(['status' => 'active']);
        $store = $this->makeStore(-6.2, 106.8, 150);
        $this->makeActiveAssignment($outsource, $store);
        $session = $this->putSession($outsource, $store, 'valid-token-1234567890abcdef1234567890abcdef');

        $result = $this->app->make(ResolveOutsourceSession::class)->execute($session->id);

        $this->assertTrue($result['valid']);
        $this->assertEquals($session->id, $result['session']->id);
        $this->assertEquals($outsource->id, $result['session']->outsourceId);
        $this->assertEquals($store->id, $result['session']->storeId);
    }

    public function test_invalid_session_rejected(): void
    {
        $result = $this->app->make(ResolveOutsourceSession::class)->execute('invalid-token');

        $this->assertFalse($result['valid']);
        $this->assertEquals('INVALID_SESSION', $result['code']);
    }

    public function test_expired_session_rejected(): void
    {
        $outsource = Outsource::factory()->create();
        $store = $this->makeStore(-6.2, 106.8);
        $this->putSession(
            $outsource,
            $store,
            'expired-token-1234567890abcdef1234567890abcdef',
            CarbonImmutable::now()->subHour(),
        );

        $result = $this->app->make(ResolveOutsourceSession::class)->execute('expired-token-1234567890abcdef1234567890abcdef');

        $this->assertFalse($result['valid']);
        $this->assertEquals('SESSION_EXPIRED', $result['code']);
    }

    public function test_session_cannot_switch_outsource_identity(): void
    {
        $outsource = Outsource::factory()->create(['status' => 'active']);
        $other = Outsource::factory()->create(['status' => 'active']);
        $store = $this->makeStore(-6.2, 106.8, 150);
        $this->makeActiveAssignment($outsource, $store);
        $session = $this->putSession($outsource, $store, 'bound-token-1234567890abcdef1234567890abcdef');

        $result = $this->app->make(ResolveOutsourceSession::class)->execute($session->id);

        $this->assertTrue($result['valid']);
        $this->assertEquals($outsource->id, $result['session']->outsourceId);
        $this->assertNotEquals($other->id, $result['session']->outsourceId);
    }

    // ============================================================
    // CHECK IN
    // ============================================================

    public function test_valid_session_inside_geofence_can_check_in(): void
    {
        $outsource = Outsource::factory()->create(['status' => 'active']);
        $store = $this->makeStore(-6.2, 106.8, 150);
        $this->makeActiveAssignment($outsource, $store);
        $session = $this->putSession($outsource, $store, 'checkin-token-234567890abcdef1234567890abcdef1');

        $response = $this->withOutsourceSession($session->id)->postJson('/api/v1/outsource/attendance/check-in', [
            'latitude' => -6.2001,
            'longitude' => 106.8001,
            'accuracy_meters' => 10,
            'device_fingerprint' => self::DEVICE_FINGERPRINT,
        ]);

        $response->assertStatus(201);
        $response->assertJson(['success' => true]);
        $this->assertDatabaseHas('attendance_records', [
            'outsource_id' => $outsource->id,
            'attendable_type' => 'outsource',
        ]);
        $this->assertDatabaseHas('attendance_events', [
            'outsource_id' => $outsource->id,
            'event_type' => 'check_in',
        ]);
    }

    public function test_check_in_outside_geofence_rejected(): void
    {
        $outsource = Outsource::factory()->create(['status' => 'active']);
        $store = $this->makeStore(-6.2, 106.8, 150);
        $this->makeActiveAssignment($outsource, $store);
        $session = $this->putSession($outsource, $store, 'geofence-token-34567890abcdef1234567890abcdef12');

        $response = $this->withOutsourceSession($session->id)->postJson('/api/v1/outsource/attendance/check-in', [
            'latitude' => -6.3,
            'longitude' => 106.9,
            'accuracy_meters' => 10,
            'device_fingerprint' => self::DEVICE_FINGERPRINT,
        ]);

        $response->assertStatus(422);
        $response->assertJson(['success' => false, 'code' => 'OUTSIDE_GEOFENCE']);
    }

    public function test_check_in_without_cookie_rejected(): void
    {
        $response = $this->postJson('/api/v1/outsource/attendance/check-in', [
            'latitude' => -6.2001,
            'longitude' => 106.8001,
            'accuracy_meters' => 10,
            'device_fingerprint' => self::DEVICE_FINGERPRINT,
        ]);

        $response->assertStatus(401);
        $response->assertJson(['code' => 'INVALID_SESSION']);
    }

    public function test_duplicate_check_in_rejected(): void
    {
        $outsource = Outsource::factory()->create(['status' => 'active']);
        $store = $this->makeStore(-6.2, 106.8, 150);
        $this->makeActiveAssignment($outsource, $store);
        $session = $this->putSession($outsource, $store, 'dup-token-4567890abcdef1234567890abcdef123');

        $this->withOutsourceSession($session->id)->postJson('/api/v1/outsource/attendance/check-in', [
            'latitude' => -6.2001,
            'longitude' => 106.8001,
            'accuracy_meters' => 10,
            'device_fingerprint' => self::DEVICE_FINGERPRINT,
        ])->assertStatus(201);

        $response = $this->withOutsourceSession($session->id)->postJson('/api/v1/outsource/attendance/check-in', [
            'latitude' => -6.2001,
            'longitude' => 106.8001,
            'accuracy_meters' => 10,
            'device_fingerprint' => self::DEVICE_FINGERPRINT,
        ]);

        $response->assertStatus(422);
        $response->assertJson(['success' => false, 'code' => 'ATTENDANCE_ALREADY_OPEN']);
    }

    // ============================================================
    // CHECK OUT
    // ============================================================

    private function openAttendanceSession(Outsource $outsource, WorkLocation $store): string
    {
        $session = $this->putSession($outsource, $store, 'checkout-token-567890abcdef1234567890abcdef1234');

        $this->withOutsourceSession($session->id)->postJson('/api/v1/outsource/attendance/check-in', [
            'latitude' => -6.2001,
            'longitude' => 106.8001,
            'accuracy_meters' => 10,
            'device_fingerprint' => self::DEVICE_FINGERPRINT,
        ])->assertStatus(201);

        return $session->id;
    }

    public function test_valid_session_with_open_attendance_can_check_out(): void
    {
        $outsource = Outsource::factory()->create(['status' => 'active']);
        $store = $this->makeStore(-6.2, 106.8, 150);
        $this->makeActiveAssignment($outsource, $store);
        $sessionId = $this->openAttendanceSession($outsource, $store);

        $response = $this->withOutsourceSession($sessionId)->postJson('/api/v1/outsource/attendance/check-out', [
            'latitude' => -6.2001,
            'longitude' => 106.8001,
            'accuracy_meters' => 10,
            'device_fingerprint' => self::DEVICE_FINGERPRINT,
        ]);

        $response->assertStatus(200);
        $response->assertJson(['success' => true]);
        $this->assertNull($this->sessionStore()->find($sessionId));
    }

    public function test_check_out_without_open_attendance_rejected(): void
    {
        $outsource = Outsource::factory()->create(['status' => 'active']);
        $store = $this->makeStore(-6.2, 106.8, 150);
        $this->makeActiveAssignment($outsource, $store);
        $session = $this->putSession($outsource, $store, 'no-open-token-67890abcdef1234567890abcdef12345');

        $response = $this->withOutsourceSession($session->id)->postJson('/api/v1/outsource/attendance/check-out', [
            'latitude' => -6.2001,
            'longitude' => 106.8001,
            'accuracy_meters' => 10,
            'device_fingerprint' => self::DEVICE_FINGERPRINT,
        ]);

        $response->assertStatus(422);
        $response->assertJson(['success' => false, 'code' => 'NO_OPEN_ATTENDANCE']);
        $this->assertNotNull($this->sessionStore()->find($session->id));
    }

    public function test_check_out_outside_geofence_keeps_session_active(): void
    {
        $outsource = Outsource::factory()->create(['status' => 'active']);
        $store = $this->makeStore(-6.2, 106.8, 150);
        $this->makeActiveAssignment($outsource, $store);
        $sessionId = $this->openAttendanceSession($outsource, $store);

        $response = $this->withOutsourceSession($sessionId)->postJson('/api/v1/outsource/attendance/check-out', [
            'latitude' => -6.3,
            'longitude' => 106.9,
            'accuracy_meters' => 10,
            'device_fingerprint' => self::DEVICE_FINGERPRINT,
        ]);

        $response->assertStatus(422);
        $response->assertJson(['success' => false, 'code' => 'OUTSIDE_GEOFENCE']);
        $this->assertNotNull($this->sessionStore()->find($sessionId));
    }

    public function test_check_out_with_expired_session_rejected(): void
    {
        $outsource = Outsource::factory()->create(['status' => 'active']);
        $store = $this->makeStore(-6.2, 106.8, 150);
        $this->makeActiveAssignment($outsource, $store);
        $session = $this->putSession(
            $outsource,
            $store,
            'expired-checkout-7890abcdef1234567890abcdef123456',
            CarbonImmutable::now()->subHour(),
        );

        $response = $this->withOutsourceSession($session->id)->postJson('/api/v1/outsource/attendance/check-out', [
            'latitude' => -6.2001,
            'longitude' => 106.8001,
            'accuracy_meters' => 10,
            'device_fingerprint' => self::DEVICE_FINGERPRINT,
        ]);

        $response->assertStatus(401);
        $response->assertJson(['code' => 'SESSION_EXPIRED']);
    }

    public function test_duplicate_checkout_rejected_after_session_deleted(): void
    {
        $outsource = Outsource::factory()->create(['status' => 'active']);
        $store = $this->makeStore(-6.2, 106.8, 150);
        $this->makeActiveAssignment($outsource, $store);
        $sessionId = $this->openAttendanceSession($outsource, $store);

        $this->withOutsourceSession($sessionId)->postJson('/api/v1/outsource/attendance/check-out', [
            'latitude' => -6.2001,
            'longitude' => 106.8001,
            'accuracy_meters' => 10,
            'device_fingerprint' => self::DEVICE_FINGERPRINT,
        ])->assertStatus(200);

        $response = $this->withOutsourceSession($sessionId)->postJson('/api/v1/outsource/attendance/check-out', [
            'latitude' => -6.2001,
            'longitude' => 106.8001,
            'accuracy_meters' => 10,
            'device_fingerprint' => self::DEVICE_FINGERPRINT,
        ]);

        $response->assertStatus(401);
        $response->assertJson(['code' => 'INVALID_SESSION']);
    }

    // ============================================================
    // CROSS-MIDNIGHT
    // ============================================================

    public function test_cross_midnight_attendance_preserves_original_date(): void
    {
        $outsource = Outsource::factory()->create(['status' => 'active']);
        $store = $this->makeStore(-6.2, 106.8, 150);
        $this->makeActiveAssignment($outsource, $store);
        $session = $this->putSession($outsource, $store, 'cross-midnight-890abcdef1234567890abcdef1234567');

        $checkInAt = CarbonImmutable::create(2026, 9, 14, 22, 0, 0, 'Asia/Jakarta');
        $checkOutAt = CarbonImmutable::create(2026, 9, 15, 6, 0, 0, 'Asia/Jakarta');

        $this->withOutsourceSession($session->id)->withHeaders([
            'X-Occurred-At' => $checkInAt->toAtomString(),
        ])->postJson('/api/v1/outsource/attendance/check-in', [
            'latitude' => -6.2001,
            'longitude' => 106.8001,
            'accuracy_meters' => 10,
            'device_fingerprint' => self::DEVICE_FINGERPRINT,
        ])->assertStatus(201);

        $checkOutResponse = $this->withOutsourceSession($session->id)->withHeaders([
            'X-Occurred-At' => $checkOutAt->toAtomString(),
        ])->postJson('/api/v1/outsource/attendance/check-out', [
            'latitude' => -6.2001,
            'longitude' => 106.8001,
            'accuracy_meters' => 10,
            'device_fingerprint' => self::DEVICE_FINGERPRINT,
        ]);
        $checkOutResponse->assertStatus(200);

        $record = AttendanceRecord::where('outsource_id', $outsource->id)->first();
        $this->assertNotNull($record);
        $this->assertEquals('2026-09-14', $record->attendance_date->toDateString());

        $attendanceSession = AttendanceSession::where('attendance_record_id', $record->id)->first();
        $this->assertNotNull($attendanceSession);
        $this->assertNotNull($attendanceSession->check_in_at);
        $this->assertNotNull($attendanceSession->check_out_at);
        $this->assertEquals(480, $attendanceSession->duration_minutes);
    }

    // ============================================================
    // SESSION LIFECYCLE
    // ============================================================

    public function test_session_lifecycle_active_until_checkout_deletes_store_entry(): void
    {
        $outsource = Outsource::factory()->create(['status' => 'active']);
        $store = $this->makeStore(-6.2, 106.8, 150);
        $this->makeActiveAssignment($outsource, $store);
        $session = $this->putSession($outsource, $store, 'lifecycle-token-90abcdef1234567890abcdef12345678');

        $this->assertEquals(OutsourceAttendanceSessionStatus::Active->value, $session->status);

        $this->withOutsourceSession($session->id)->postJson('/api/v1/outsource/attendance/check-in', [
            'latitude' => -6.2001,
            'longitude' => 106.8001,
            'accuracy_meters' => 10,
            'device_fingerprint' => self::DEVICE_FINGERPRINT,
        ])->assertStatus(201);

        $this->assertNotNull($this->sessionStore()->find($session->id));

        $this->withOutsourceSession($session->id)->postJson('/api/v1/outsource/attendance/check-out', [
            'latitude' => -6.2001,
            'longitude' => 106.8001,
            'accuracy_meters' => 10,
            'device_fingerprint' => self::DEVICE_FINGERPRINT,
        ])->assertStatus(200);

        $this->assertNull($this->sessionStore()->find($session->id));
    }

    public function test_second_check_in_same_day_after_checkout_is_rejected(): void
    {
        $city = City::factory()->create();
        $store = $this->makeStore(-6.2, 106.8, 150, $city->id);
        $outsource = Outsource::factory()->create(['status' => 'active']);
        $this->makeActiveAssignment($outsource, $store);

        $init1 = $this->postJson('/api/v1/outsource/session/init', $this->initPayload($city, $store, $outsource));
        $sid1 = (string) $init1->getCookie($this->cookieName(), false)?->getValue();

        $this->withOutsourceSession($sid1)->postJson('/api/v1/outsource/attendance/check-in', [
            'latitude' => -6.2001,
            'longitude' => 106.8001,
            'accuracy_meters' => 10,
            'device_fingerprint' => self::DEVICE_FINGERPRINT,
        ])->assertStatus(201);

        $this->withOutsourceSession($sid1)->postJson('/api/v1/outsource/attendance/check-out', [
            'latitude' => -6.2001,
            'longitude' => 106.8001,
            'accuracy_meters' => 10,
            'device_fingerprint' => self::DEVICE_FINGERPRINT,
        ])->assertStatus(200);

        $init2 = $this->postJson('/api/v1/outsource/session/init', $this->initPayload($city, $store, $outsource));
        $sid2 = (string) $init2->getCookie($this->cookieName(), false)?->getValue();
        $init2->assertJsonPath('data.status', 'READY');

        $in2 = $this->withOutsourceSession($sid2)->postJson('/api/v1/outsource/attendance/check-in', [
            'latitude' => -6.2001,
            'longitude' => 106.8001,
            'accuracy_meters' => 10,
            'device_fingerprint' => self::DEVICE_FINGERPRINT,
        ]);
        $in2->assertStatus(422);
        $in2->assertJson(['success' => false, 'code' => 'ATTENDANCE_DAY_COMPLETED']);

        $this->assertDatabaseCount('attendance_records', 1);
        $this->assertEquals(1, AttendanceSession::query()->count());
    }

    public function test_checkout_after_max_session_hours_marks_incomplete_and_rejects(): void
    {
        $outsource = Outsource::factory()->create(['status' => 'active']);
        $store = $this->makeStore(-6.2, 106.8, 150);
        $this->makeActiveAssignment($outsource, $store);

        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-09-01 20:00:00', 'UTC'));

        $session = $this->putSession(
            $outsource,
            $store,
            'expire-token-abcdef1234567890abcdef1234567890',
            CarbonImmutable::parse('2026-09-03 20:00:00', 'UTC'),
        );

        $this->withOutsourceSession($session->id)->postJson('/api/v1/outsource/attendance/check-in', [
            'latitude' => -6.2001,
            'longitude' => 106.8001,
            'accuracy_meters' => 10,
            'device_fingerprint' => self::DEVICE_FINGERPRINT,
        ])->assertStatus(201);

        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-09-02 16:01:00', 'UTC')); // 20h+1m

        $response = $this->withOutsourceSession($session->id)->postJson('/api/v1/outsource/attendance/check-out', [
            'latitude' => -6.2001,
            'longitude' => 106.8001,
            'accuracy_meters' => 10,
            'device_fingerprint' => self::DEVICE_FINGERPRINT,
        ]);

        $response->assertStatus(422);
        $response->assertJson(['success' => false, 'code' => 'ATTENDANCE_SESSION_EXPIRED']);

        $attendanceSession = AttendanceSession::query()->first();
        $this->assertNotNull($attendanceSession);
        $this->assertSame('expired', $attendanceSession->status);
        $this->assertNull($attendanceSession->check_out_at);

        $record = AttendanceRecord::query()->first();
        $this->assertNotNull($record);
        $this->assertSame('incomplete', $record->status);

        CarbonImmutable::setTestNow();
    }

    // ============================================================
    // RATE LIMITING
    // ============================================================

    public function test_session_init_rate_limited(): void
    {
        $city = City::factory()->create();
        $outsource = Outsource::factory()->create(['status' => 'active']);
        $store = $this->makeStore(-6.2, 106.8, 150, $city->id);
        $this->makeActiveAssignment($outsource, $store);

        for ($i = 0; $i < 10; $i++) {
            $this->postJson('/api/v1/outsource/session/init', $this->initPayload($city, $store, $outsource));
        }

        $response = $this->postJson('/api/v1/outsource/session/init', $this->initPayload($city, $store, $outsource));

        $response->assertStatus(429);
    }
}
