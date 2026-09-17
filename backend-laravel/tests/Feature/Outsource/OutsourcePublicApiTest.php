<?php

namespace Tests\Feature\Outsource;

use App\Actions\Outsource\InitializeOutsourceAttendanceSession;
use App\Actions\Outsource\OutsourceCheckIn;
use App\Actions\Outsource\OutsourceCheckOut;
use App\Actions\Outsource\ResolveOutsourceSession;
use App\Enums\OutsourceAttendanceSessionStatus;
use App\Models\AttendanceRecord;
use App\Models\AttendanceSession;
use App\Models\City;
use App\Models\Outsource;
use App\Models\OutsourceAttendanceSession;
use App\Models\OutsourceStoreAssignment;
use App\Models\WorkLocation;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OutsourcePublicApiTest extends TestCase
{
    use RefreshDatabase;

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

        $response = $this->getJson('/api/v1/outsource/stores?city_id=' . $city1->id);

        $response->assertStatus(200);
        $response->assertJsonCount(1, 'data');
        $response->assertJsonPath('data.0.id', $store1->id);
    }

    public function test_outsources_endpoint_filters_by_store(): void
    {
        $outsource = Outsource::factory()->create();
        $store = WorkLocation::factory()->create();
        $this->makeActiveAssignment($outsource, $store);

        $otherOutsource = Outsource::factory()->create();
        $otherStore = WorkLocation::factory()->create();

        $response = $this->getJson('/api/v1/outsource/outsources?store_id=' . $store->id);

        $response->assertStatus(200);
        $response->assertJsonCount(1, 'data');
        $response->assertJsonPath('data.0.id', $outsource->id);
    }

    public function test_stores_endpoint_excludes_inactive_stores(): void
    {
        $city = City::factory()->create();
        WorkLocation::factory()->create(['city_id' => $city->id, 'status' => 'inactive']);

        $response = $this->getJson('/api/v1/outsource/stores?city_id=' . $city->id);

        $response->assertStatus(200);
        $response->assertJsonCount(0, 'data');
    }

    public function test_outsources_endpoint_excludes_inactive_outsources(): void
    {
        $outsource = Outsource::factory()->create(['status' => 'inactive']);
        $store = WorkLocation::factory()->create();
        $this->makeActiveAssignment($outsource, $store);

        $response = $this->getJson('/api/v1/outsource/outsources?store_id=' . $store->id);

        $response->assertStatus(200);
        $response->assertJsonCount(0, 'data');
    }

    // ============================================================
    // SESSION INIT
    // ============================================================

    public function test_valid_assignment_creates_session(): void
    {
        $city = City::factory()->create();
        $outsource = Outsource::factory()->create(['status' => 'active']);
        $store = $this->makeStore(-6.2, 106.8, 150, $city->id);
        $this->makeActiveAssignment($outsource, $store);

        $response = $this->postJson('/api/v1/outsource/session/init', [
            'city_id' => $city->id,
            'store_id' => $store->id,
            'outsource_id' => $outsource->id,
        ]);

        $response->assertStatus(201);
        $response->assertJson(['success' => true]);
        $response->assertJsonStructure([
            'data' => [
                'session_token',
                'expires_at',
                'outsource' => ['id', 'name', 'outsource_code'],
                'store' => ['id', 'name'],
            ],
        ]);
        $this->assertDatabaseHas('outsource_attendance_sessions', [
            'outsource_id' => $outsource->id,
            'work_location_id' => $store->id,
            'status' => OutsourceAttendanceSessionStatus::Active->value,
        ]);
    }

    public function test_invalid_assignment_rejected(): void
    {
        $city = City::factory()->create();
        $outsource = Outsource::factory()->create(['status' => 'active']);
        $store = $this->makeStore(-6.2, 106.8, 150, $city->id);

        $response = $this->postJson('/api/v1/outsource/session/init', [
            'city_id' => $city->id,
            'store_id' => $store->id,
            'outsource_id' => $outsource->id,
        ]);

        $response->assertStatus(422);
        $response->assertJson(['success' => false]);
    }

    public function test_inactive_outsource_rejected(): void
    {
        $city = City::factory()->create();
        $outsource = Outsource::factory()->create(['status' => 'inactive']);
        $store = $this->makeStore(-6.2, 106.8, 150, $city->id);

        $response = $this->postJson('/api/v1/outsource/session/init', [
            'city_id' => $city->id,
            'store_id' => $store->id,
            'outsource_id' => $outsource->id,
        ]);

        $response->assertStatus(422);
    }

    public function test_inactive_store_rejected(): void
    {
        $city = City::factory()->create();
        $outsource = Outsource::factory()->create(['status' => 'active']);
        $store = $this->makeStore(-6.2, 106.8, 150, $city->id);
        $store->update(['status' => 'inactive']);

        $response = $this->postJson('/api/v1/outsource/session/init', [
            'city_id' => $city->id,
            'store_id' => $store->id,
            'outsource_id' => $outsource->id,
        ]);

        $response->assertStatus(422);
    }

    public function test_store_city_mismatch_rejected(): void
    {
        $city1 = City::factory()->create();
        $city2 = City::factory()->create();
        $outsource = Outsource::factory()->create(['status' => 'active']);
        $store = $this->makeStore(-6.2, 106.8, 150, $city2->id);
        $this->makeActiveAssignment($outsource, $store);

        $response = $this->postJson('/api/v1/outsource/session/init', [
            'city_id' => $city1->id,
            'store_id' => $store->id,
            'outsource_id' => $outsource->id,
        ]);

        $response->assertStatus(422);
    }

    public function test_raw_token_returned_once(): void
    {
        $city = City::factory()->create();
        $outsource = Outsource::factory()->create(['status' => 'active']);
        $store = $this->makeStore(-6.2, 106.8, 150, $city->id);
        $this->makeActiveAssignment($outsource, $store);

        $response = $this->postJson('/api/v1/outsource/session/init', [
            'city_id' => $city->id,
            'store_id' => $store->id,
            'outsource_id' => $outsource->id,
        ]);

        $response->assertStatus(201);
        $token = $response->json('data.session_token');
        $this->assertNotEmpty($token);
        $this->assertDatabaseHas('outsource_attendance_sessions', [
            'token_hash' => hash('sha256', $token),
        ]);
    }

    public function test_token_hash_stored_not_raw_token(): void
    {
        $city = City::factory()->create();
        $outsource = Outsource::factory()->create(['status' => 'active']);
        $store = $this->makeStore(-6.2, 106.8, 150, $city->id);
        $this->makeActiveAssignment($outsource, $store);

        $response = $this->postJson('/api/v1/outsource/session/init', [
            'city_id' => $city->id,
            'store_id' => $store->id,
            'outsource_id' => $outsource->id,
        ]);

        $token = $response->json('data.session_token');
        $session = OutsourceAttendanceSession::first();

        $this->assertNotEquals($token, $session->token_hash);
        $this->assertEquals(hash('sha256', $token), $session->token_hash);
    }

    public function test_token_not_predictable_from_ids(): void
    {
        $city = City::factory()->create();
        $outsource = Outsource::factory()->create(['status' => 'active']);
        $store = $this->makeStore(-6.2, 106.8, 150, $city->id);
        $this->makeActiveAssignment($outsource, $store);

        $response1 = $this->postJson('/api/v1/outsource/session/init', [
            'city_id' => $city->id,
            'store_id' => $store->id,
            'outsource_id' => $outsource->id,
        ]);

        $response2 = $this->postJson('/api/v1/outsource/session/init', [
            'city_id' => $city->id,
            'store_id' => $store->id,
            'outsource_id' => $outsource->id,
        ]);

        $this->assertNotEquals($response1->json('data.session_token'), $response2->json('data.session_token'));
    }

    // ============================================================
    // SESSION RESOLVER
    // ============================================================

    private function createValidSession(): OutsourceAttendanceSession
    {
        $outsource = Outsource::factory()->create(['status' => 'active']);
        $store = $this->makeStore(-6.2, 106.8, 150);
        $this->makeActiveAssignment($outsource, $store);

        return OutsourceAttendanceSession::create([
            'outsource_id' => $outsource->id,
            'work_location_id' => $store->id,
            'token_hash' => hash('sha256', 'valid-token-123'),
            'status' => OutsourceAttendanceSessionStatus::Active->value,
            'expires_at' => now()->addHours(12),
            'last_used_at' => now(),
        ]);
    }

    public function test_valid_token_resolves_correct_session(): void
    {
        $session = $this->createValidSession();
        $resolver = new ResolveOutsourceSession();

        $result = $resolver->execute('valid-token-123');

        $this->assertTrue($result['valid']);
        $this->assertEquals($session->id, $result['session']->id);
        $this->assertEquals($session->outsource_id, $result['session']->outsource_id);
        $this->assertEquals($session->work_location_id, $result['session']->work_location_id);
    }

    public function test_invalid_token_rejected(): void
    {
        $resolver = new ResolveOutsourceSession();
        $result = $resolver->execute('invalid-token');

        $this->assertFalse($result['valid']);
        $this->assertEquals('INVALID_SESSION', $result['code']);
    }

    public function test_expired_token_rejected(): void
    {
        OutsourceAttendanceSession::create([
            'outsource_id' => Outsource::factory()->create()->id,
            'work_location_id' => $this->makeStore(-6.2, 106.8)->id,
            'token_hash' => hash('sha256', 'expired-token'),
            'status' => OutsourceAttendanceSessionStatus::Active->value,
            'expires_at' => now()->subHour(),
            'last_used_at' => now()->subHour(),
        ]);

        $resolver = new ResolveOutsourceSession();
        $result = $resolver->execute('expired-token');

        $this->assertFalse($result['valid']);
        $this->assertEquals('SESSION_EXPIRED', $result['code']);
    }

    public function test_revoked_token_rejected(): void
    {
        OutsourceAttendanceSession::create([
            'outsource_id' => Outsource::factory()->create()->id,
            'work_location_id' => $this->makeStore(-6.2, 106.8)->id,
            'token_hash' => hash('sha256', 'revoked-token'),
            'status' => OutsourceAttendanceSessionStatus::Revoked->value,
            'expires_at' => now()->addHours(12),
        ]);

        $resolver = new ResolveOutsourceSession();
        $result = $resolver->execute('revoked-token');

        $this->assertFalse($result['valid']);
        $this->assertEquals('SESSION_REVOKED', $result['code']);
    }

    public function test_completed_token_rejected(): void
    {
        OutsourceAttendanceSession::create([
            'outsource_id' => Outsource::factory()->create()->id,
            'work_location_id' => $this->makeStore(-6.2, 106.8)->id,
            'token_hash' => hash('sha256', 'completed-token'),
            'status' => OutsourceAttendanceSessionStatus::Completed->value,
            'expires_at' => now()->addHours(12),
            'completed_at' => now(),
        ]);

        $resolver = new ResolveOutsourceSession();
        $result = $resolver->execute('completed-token');

        $this->assertFalse($result['valid']);
        $this->assertEquals('SESSION_COMPLETED', $result['code']);
    }

    public function test_token_cannot_switch_outsource(): void
    {
        $session = $this->createValidSession();
        $otherOutsource = Outsource::factory()->create(['status' => 'active']);

        // Even if we try to use the token for a different outsource, the session resolves to the correct one
        $resolver = new ResolveOutsourceSession();
        $result = $resolver->execute('valid-token-123');

        $this->assertTrue($result['valid']);
        $this->assertEquals($session->outsource_id, $result['session']->outsource_id);
        $this->assertNotEquals($otherOutsource->id, $result['session']->outsource_id);
    }

    // ============================================================
    // CHECK IN
    // ============================================================

    public function test_valid_session_inside_geofence_can_check_in(): void
    {
        $outsource = Outsource::factory()->create(['status' => 'active']);
        $store = $this->makeStore(-6.2, 106.8, 150);
        $this->makeActiveAssignment($outsource, $store);
        $session = OutsourceAttendanceSession::create([
            'outsource_id' => $outsource->id,
            'work_location_id' => $store->id,
            'token_hash' => hash('sha256', 'checkin-token'),
            'status' => OutsourceAttendanceSessionStatus::Active->value,
            'expires_at' => now()->addHours(12),
        ]);

        $response = $this->postJson('/api/v1/outsource/attendance/check-in', [
            'latitude' => -6.2001,
            'longitude' => 106.8001,
            'accuracy_meters' => 10,
        ], [
            'Authorization' => 'Bearer checkin-token',
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
        OutsourceAttendanceSession::create([
            'outsource_id' => $outsource->id,
            'work_location_id' => $store->id,
            'token_hash' => hash('sha256', 'geofence-token'),
            'status' => OutsourceAttendanceSessionStatus::Active->value,
            'expires_at' => now()->addHours(12),
        ]);

        $response = $this->postJson('/api/v1/outsource/attendance/check-in', [
            'latitude' => -6.3,
            'longitude' => 106.9,
            'accuracy_meters' => 10,
        ], [
            'Authorization' => 'Bearer geofence-token',
        ]);

        $response->assertStatus(422);
        $response->assertJson(['success' => false, 'code' => 'OUTSIDE_GEOFENCE']);
    }

    public function test_check_in_with_deactivated_assignment_rejected(): void
    {
        $outsource = Outsource::factory()->create(['status' => 'active']);
        $store = $this->makeStore(-6.2, 106.8, 150);
        $assignment = $this->makeActiveAssignment($outsource, $store);
        $assignment->update(['status' => 'inactive']);

        OutsourceAttendanceSession::create([
            'outsource_id' => $outsource->id,
            'work_location_id' => $store->id,
            'token_hash' => hash('sha256', 'assignment-token'),
            'status' => OutsourceAttendanceSessionStatus::Active->value,
            'expires_at' => now()->addHours(12),
        ]);

        $response = $this->postJson('/api/v1/outsource/attendance/check-in', [
            'latitude' => -6.2001,
            'longitude' => 106.8001,
            'accuracy_meters' => 10,
        ], [
            'Authorization' => 'Bearer assignment-token',
        ]);

        $response->assertStatus(422);
        $response->assertJson(['success' => false, 'code' => 'INVALID_ASSIGNMENT']);
    }

    public function test_check_in_with_deactivated_store_rejected(): void
    {
        $outsource = Outsource::factory()->create(['status' => 'active']);
        $store = $this->makeStore(-6.2, 106.8, 150);
        $this->makeActiveAssignment($outsource, $store);
        $store->update(['status' => 'inactive']);

        OutsourceAttendanceSession::create([
            'outsource_id' => $outsource->id,
            'work_location_id' => $store->id,
            'token_hash' => hash('sha256', 'store-token'),
            'status' => OutsourceAttendanceSessionStatus::Active->value,
            'expires_at' => now()->addHours(12),
        ]);

        $response = $this->postJson('/api/v1/outsource/attendance/check-in', [
            'latitude' => -6.2001,
            'longitude' => 106.8001,
            'accuracy_meters' => 10,
        ], [
            'Authorization' => 'Bearer store-token',
        ]);

        $response->assertStatus(422);
        $response->assertJson(['success' => false, 'code' => 'INACTIVE_STORE']);
    }

    public function test_check_in_with_deactivated_outsource_rejected(): void
    {
        $outsource = Outsource::factory()->create(['status' => 'active']);
        $store = $this->makeStore(-6.2, 106.8, 150);
        $this->makeActiveAssignment($outsource, $store);
        $outsource->update(['status' => 'inactive']);

        OutsourceAttendanceSession::create([
            'outsource_id' => $outsource->id,
            'work_location_id' => $store->id,
            'token_hash' => hash('sha256', 'outsource-token'),
            'status' => OutsourceAttendanceSessionStatus::Active->value,
            'expires_at' => now()->addHours(12),
        ]);

        $response = $this->postJson('/api/v1/outsource/attendance/check-in', [
            'latitude' => -6.2001,
            'longitude' => 106.8001,
            'accuracy_meters' => 10,
        ], [
            'Authorization' => 'Bearer outsource-token',
        ]);

        $response->assertStatus(422);
        $response->assertJson(['success' => false, 'code' => 'INACTIVE_OUTSOURCE']);
    }

    public function test_duplicate_check_in_rejected(): void
    {
        $outsource = Outsource::factory()->create(['status' => 'active']);
        $store = $this->makeStore(-6.2, 106.8, 150);
        $this->makeActiveAssignment($outsource, $store);
        OutsourceAttendanceSession::create([
            'outsource_id' => $outsource->id,
            'work_location_id' => $store->id,
            'token_hash' => hash('sha256', 'dup-token'),
            'status' => OutsourceAttendanceSessionStatus::Active->value,
            'expires_at' => now()->addHours(12),
        ]);

        $this->postJson('/api/v1/outsource/attendance/check-in', [
            'latitude' => -6.2001,
            'longitude' => 106.8001,
            'accuracy_meters' => 10,
        ], [
            'Authorization' => 'Bearer dup-token',
        ])->assertStatus(201);

        $response = $this->postJson('/api/v1/outsource/attendance/check-in', [
            'latitude' => -6.2001,
            'longitude' => 106.8001,
            'accuracy_meters' => 10,
        ], [
            'Authorization' => 'Bearer dup-token',
        ]);

        $response->assertStatus(422);
        $response->assertJson(['success' => false, 'code' => 'ATTENDANCE_ALREADY_OPEN']);
    }

    // ============================================================
    // CHECK OUT
    // ============================================================

    private function openAttendanceSession(Outsource $outsource, WorkLocation $store): OutsourceAttendanceSession
    {
        $session = OutsourceAttendanceSession::create([
            'outsource_id' => $outsource->id,
            'work_location_id' => $store->id,
            'token_hash' => hash('sha256', 'checkout-token'),
            'status' => OutsourceAttendanceSessionStatus::Active->value,
            'expires_at' => now()->addHours(12),
        ]);

        $this->postJson('/api/v1/outsource/attendance/check-in', [
            'latitude' => -6.2001,
            'longitude' => 106.8001,
            'accuracy_meters' => 10,
        ], [
            'Authorization' => 'Bearer checkout-token',
        ])->assertStatus(201);

        return $session;
    }

    public function test_valid_session_with_open_attendance_can_check_out(): void
    {
        $outsource = Outsource::factory()->create(['status' => 'active']);
        $store = $this->makeStore(-6.2, 106.8, 150);
        $this->makeActiveAssignment($outsource, $store);
        $this->openAttendanceSession($outsource, $store);

        $response = $this->postJson('/api/v1/outsource/attendance/check-out', [
            'latitude' => -6.2001,
            'longitude' => 106.8001,
            'accuracy_meters' => 10,
        ], [
            'Authorization' => 'Bearer checkout-token',
        ]);

        $response->assertStatus(200);
        $response->assertJson(['success' => true]);

        $session = OutsourceAttendanceSession::where('token_hash', hash('sha256', 'checkout-token'))->first();
        $this->assertEquals(OutsourceAttendanceSessionStatus::Completed->value, $session->status);
        $this->assertNotNull($session->completed_at);
    }

    public function test_check_out_without_open_attendance_rejected(): void
    {
        $outsource = Outsource::factory()->create(['status' => 'active']);
        $store = $this->makeStore(-6.2, 106.8, 150);
        $this->makeActiveAssignment($outsource, $store);
        OutsourceAttendanceSession::create([
            'outsource_id' => $outsource->id,
            'work_location_id' => $store->id,
            'token_hash' => hash('sha256', 'no-open-token'),
            'status' => OutsourceAttendanceSessionStatus::Active->value,
            'expires_at' => now()->addHours(12),
        ]);

        $response = $this->postJson('/api/v1/outsource/attendance/check-out', [
            'latitude' => -6.2001,
            'longitude' => 106.8001,
            'accuracy_meters' => 10,
        ], [
            'Authorization' => 'Bearer no-open-token',
        ]);

        $response->assertStatus(422);
        $response->assertJson(['success' => false, 'code' => 'NO_OPEN_ATTENDANCE']);
    }

    public function test_check_out_outside_geofence_rejected(): void
    {
        $outsource = Outsource::factory()->create(['status' => 'active']);
        $store = $this->makeStore(-6.2, 106.8, 150);
        $this->makeActiveAssignment($outsource, $store);
        $this->openAttendanceSession($outsource, $store);

        $response = $this->postJson('/api/v1/outsource/attendance/check-out', [
            'latitude' => -6.3,
            'longitude' => 106.9,
            'accuracy_meters' => 10,
        ], [
            'Authorization' => 'Bearer checkout-token',
        ]);

        $response->assertStatus(422);
        $response->assertJson(['success' => false, 'code' => 'OUTSIDE_GEOFENCE']);
    }

    public function test_check_out_with_expired_session_rejected(): void
    {
        $outsource = Outsource::factory()->create(['status' => 'active']);
        $store = $this->makeStore(-6.2, 106.8, 150);
        $this->makeActiveAssignment($outsource, $store);
        OutsourceAttendanceSession::create([
            'outsource_id' => $outsource->id,
            'work_location_id' => $store->id,
            'token_hash' => hash('sha256', 'expired-checkout-token'),
            'status' => OutsourceAttendanceSessionStatus::Active->value,
            'expires_at' => now()->subHour(),
        ]);

        $this->postJson('/api/v1/outsource/attendance/check-in', [
            'latitude' => -6.2001,
            'longitude' => 106.8001,
            'accuracy_meters' => 10,
        ], [
            'Authorization' => 'Bearer expired-checkout-token',
        ])->assertStatus(401);

        $response = $this->postJson('/api/v1/outsource/attendance/check-out', [
            'latitude' => -6.2001,
            'longitude' => 106.8001,
            'accuracy_meters' => 10,
        ], [
            'Authorization' => 'Bearer expired-checkout-token',
        ]);

        $response->assertStatus(401);
        $response->assertJson(['code' => 'SESSION_EXPIRED']);
    }

    public function test_check_out_with_deactivated_assignment_rejected(): void
    {
        $outsource = Outsource::factory()->create(['status' => 'active']);
        $store = $this->makeStore(-6.2, 106.8, 150);
        $assignment = $this->makeActiveAssignment($outsource, $store);
        $this->openAttendanceSession($outsource, $store);
        $assignment->update(['status' => 'inactive']);

        $response = $this->postJson('/api/v1/outsource/attendance/check-out', [
            'latitude' => -6.2001,
            'longitude' => 106.8001,
            'accuracy_meters' => 10,
        ], [
            'Authorization' => 'Bearer checkout-token',
        ]);

        $response->assertStatus(422);
        $response->assertJson(['success' => false, 'code' => 'INVALID_ASSIGNMENT']);
    }

    public function test_duplicate_checkout_rejected(): void
    {
        $outsource = Outsource::factory()->create(['status' => 'active']);
        $store = $this->makeStore(-6.2, 106.8, 150);
        $this->makeActiveAssignment($outsource, $store);
        $this->openAttendanceSession($outsource, $store);

        $this->postJson('/api/v1/outsource/attendance/check-out', [
            'latitude' => -6.2001,
            'longitude' => 106.8001,
            'accuracy_meters' => 10,
        ], [
            'Authorization' => 'Bearer checkout-token',
        ])->assertStatus(200);

        $response = $this->postJson('/api/v1/outsource/attendance/check-out', [
            'latitude' => -6.2001,
            'longitude' => 106.8001,
            'accuracy_meters' => 10,
        ], [
            'Authorization' => 'Bearer checkout-token',
        ]);

        $response->assertStatus(401);
        $response->assertJson(['code' => 'SESSION_COMPLETED']);
    }

    // ============================================================
    // CROSS-MIDNIGHT
    // ============================================================

    public function test_cross_midnight_attendance_preserves_original_date(): void
    {
        $outsource = Outsource::factory()->create(['status' => 'active']);
        $store = $this->makeStore(-6.2, 106.8, 150);
        $this->makeActiveAssignment($outsource, $store);

        $tokenHash = hash('sha256', 'cross-midnight-token');
        OutsourceAttendanceSession::create([
            'outsource_id' => $outsource->id,
            'work_location_id' => $store->id,
            'token_hash' => $tokenHash,
            'status' => OutsourceAttendanceSessionStatus::Active->value,
            'expires_at' => now()->addHours(12),
        ]);

        $checkInAt = CarbonImmutable::create(2026, 9, 14, 22, 0, 0);
        $checkOutAt = CarbonImmutable::create(2026, 9, 15, 6, 0, 0);

        $this->withHeaders([
            'Authorization' => 'Bearer cross-midnight-token',
            'X-Occurred-At' => $checkInAt->toAtomString(),
        ])->postJson('/api/v1/outsource/attendance/check-in', [
            'latitude' => -6.2001,
            'longitude' => 106.8001,
            'accuracy_meters' => 10,
        ])->assertStatus(201);

        $this->withHeaders([
            'Authorization' => 'Bearer cross-midnight-token',
            'X-Occurred-At' => $checkOutAt->toAtomString(),
        ])->postJson('/api/v1/outsource/attendance/check-out', [
            'latitude' => -6.2001,
            'longitude' => 106.8001,
            'accuracy_meters' => 10,
        ])->assertStatus(200);

        $record = AttendanceRecord::where('outsource_id', $outsource->id)->first();
        $this->assertNotNull($record);
        $this->assertEquals('2026-09-14', $record->attendance_date->toDateString());

        $session = AttendanceSession::where('attendance_record_id', $record->id)->first();
        $this->assertNotNull($session);
        $this->assertEquals('2026-09-14 22:00:00', $session->check_in_at);
        $this->assertEquals('2026-09-15 06:00:00', $session->check_out_at);
        $this->assertEquals(480, $session->duration_minutes);
    }

    // ============================================================
    // SESSION LIFECYCLE
    // ============================================================

    public function test_session_lifecycle_active_to_completed(): void
    {
        $outsource = Outsource::factory()->create(['status' => 'active']);
        $store = $this->makeStore(-6.2, 106.8, 150);
        $this->makeActiveAssignment($outsource, $store);

        $session = OutsourceAttendanceSession::create([
            'outsource_id' => $outsource->id,
            'work_location_id' => $store->id,
            'token_hash' => hash('sha256', 'lifecycle-token'),
            'status' => OutsourceAttendanceSessionStatus::Active->value,
            'expires_at' => now()->addHours(12),
        ]);

        $this->assertEquals(OutsourceAttendanceSessionStatus::Active->value, $session->status);
        $this->assertNull($session->completed_at);

        $this->postJson('/api/v1/outsource/attendance/check-in', [
            'latitude' => -6.2001,
            'longitude' => 106.8001,
            'accuracy_meters' => 10,
        ], [
            'Authorization' => 'Bearer lifecycle-token',
        ])->assertStatus(201);

        $session->refresh();
        $this->assertEquals(OutsourceAttendanceSessionStatus::Active->value, $session->status);
        $this->assertNull($session->completed_at);

        $this->postJson('/api/v1/outsource/attendance/check-out', [
            'latitude' => -6.2001,
            'longitude' => 106.8001,
            'accuracy_meters' => 10,
        ], [
            'Authorization' => 'Bearer lifecycle-token',
        ])->assertStatus(200);

        $session->refresh();
        $this->assertEquals(OutsourceAttendanceSessionStatus::Completed->value, $session->status);
        $this->assertNotNull($session->completed_at);
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
            $this->postJson('/api/v1/outsource/session/init', [
                'city_id' => $city->id,
                'store_id' => $store->id,
                'outsource_id' => $outsource->id,
            ]);
        }

        $response = $this->postJson('/api/v1/outsource/session/init', [
            'city_id' => $city->id,
            'store_id' => $store->id,
            'outsource_id' => $outsource->id,
        ]);

        $response->assertStatus(429);
    }
}
