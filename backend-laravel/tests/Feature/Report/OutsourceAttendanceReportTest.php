<?php

namespace Tests\Feature\Report;

use App\Models\AttendanceRecord;
use App\Models\AttendanceSession;
use App\Models\City;
use App\Models\Employee;
use App\Models\Outsource;
use App\Models\OutsourceStoreAssignment;
use App\Models\User;
use App\Models\WorkLocation;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class OutsourceAttendanceReportTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    private function makeUser(string $role = 'USER', array $overrides = []): User
    {
        $user = User::factory()->create($overrides);
        $user->assignRole($role);

        return $user;
    }

    private function makeStore(string $name, ?int $cityId = null): WorkLocation
    {
        return WorkLocation::factory()->create([
            'name' => $name,
            'city_id' => $cityId,
            'status' => 'active',
        ]);
    }

    private function makeActiveAssignment(Outsource $outsource, WorkLocation $store): OutsourceStoreAssignment
    {
        return OutsourceStoreAssignment::factory()
            ->forOutsource($outsource)
            ->forStore($store)
            ->create(['status' => 'active']);
    }

    private function makeOutsourceRecord(
        Outsource $outsource,
        string $date,
        ?string $status = 'present',
        ?int $checkInMinutes = 480,
    ): AttendanceRecord {
        $record = AttendanceRecord::create([
            'outsource_id' => $outsource->id,
            'attendable_type' => 'outsource',
            'attendance_date' => $date,
            'status' => $status ?? 'present',
        ]);

        if ($checkInMinutes !== null) {
            $checkIn = \Carbon\Carbon::create($date.' 08:00:00');
            $checkOut = $checkIn->copy()->addMinutes($checkInMinutes);

            AttendanceSession::factory()->forRecord($record)->closed()->create([
                'check_in_at' => $checkIn,
                'check_out_at' => $checkOut,
                'duration_minutes' => $checkInMinutes,
            ]);
        }

        return $record;
    }

    // ========================
    // Authorization
    // ========================

    public function test_outsource_attendance_report_requires_auth(): void
    {
        $this->getJson('/api/v1/reports/outsource-attendance?from=2026-09-01&to=2026-09-30')
            ->assertUnauthorized();
    }

    public function test_outsource_attendance_report_requires_permission(): void
    {
        $user = $this->makeUser('USER');

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/reports/outsource-attendance?from=2026-09-01&to=2026-09-30')
            ->assertForbidden();
    }

    public function test_user_with_permission_can_view_report(): void
    {
        $user = $this->makeUser('USER');
        $user->givePermissionTo('outsource_attendance.view');

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/reports/outsource-attendance?from=2026-09-01&to=2026-09-30')
            ->assertOk()
            ->assertJson(['success' => true]);
    }

    public function test_admin_can_view_report(): void
    {
        $admin = $this->makeUser('ADMIN');

        $this->actingAs($admin, 'sanctum')
            ->getJson('/api/v1/reports/outsource-attendance?from=2026-09-01&to=2026-09-30')
            ->assertOk()
            ->assertJson(['success' => true]);
    }

    public function test_super_admin_can_view_report(): void
    {
        $superAdmin = $this->makeUser('SUPER_ADMIN');

        $this->actingAs($superAdmin, 'sanctum')
            ->getJson('/api/v1/reports/outsource-attendance?from=2026-09-01&to=2026-09-30')
            ->assertOk()
            ->assertJson(['success' => true]);
    }

    // ========================
    // Data Isolation
    // ========================

    public function test_only_outsource_attendance_returned(): void
    {
        $user = $this->makeUser('USER');
        $user->givePermissionTo('outsource_attendance.view');

        $outsource = Outsource::factory()->create();
        $employee = Employee::factory()->create();

        $this->makeOutsourceRecord($outsource, '2026-09-10');
        AttendanceRecord::create([
            'employee_id' => $employee->id,
            'attendable_type' => 'employee',
            'attendance_date' => '2026-09-10',
            'status' => 'present',
        ]);

        $response = $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/reports/outsource-attendance?from=2026-09-01&to=2026-09-30');

        $response->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.outsource.id', $outsource->id);
    }

    public function test_employee_attendance_excluded(): void
    {
        $user = $this->makeUser('USER');
        $user->givePermissionTo('outsource_attendance.view');

        $employee = Employee::factory()->create();

        AttendanceRecord::create([
            'employee_id' => $employee->id,
            'attendable_type' => 'employee',
            'attendance_date' => '2026-09-10',
            'status' => 'present',
        ]);

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/reports/outsource-attendance?from=2026-09-01&to=2026-09-30')
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }

    // ========================
    // Filters
    // ========================

    public function test_date_range_filter(): void
    {
        $user = $this->makeUser('USER');
        $user->givePermissionTo('outsource_attendance.view');

        $outsource = Outsource::factory()->create();
        $this->makeOutsourceRecord($outsource, '2026-09-10');
        $this->makeOutsourceRecord($outsource, '2026-10-05');

        $response = $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/reports/outsource-attendance?from=2026-09-01&to=2026-09-30');

        $response->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.attendance_date', '2026-09-10');
    }

    public function test_outsource_filter(): void
    {
        $user = $this->makeUser('USER');
        $user->givePermissionTo('outsource_attendance.view');

        $outsourceA = Outsource::factory()->create();
        $outsourceB = Outsource::factory()->create();

        $this->makeOutsourceRecord($outsourceA, '2026-09-10');
        $this->makeOutsourceRecord($outsourceB, '2026-09-10');

        $response = $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/reports/outsource-attendance?from=2026-09-01&to=2026-09-30&outsource_id='.$outsourceA->id);

        $response->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.outsource.id', $outsourceA->id);
    }

    public function test_status_filter(): void
    {
        $user = $this->makeUser('USER');
        $user->givePermissionTo('outsource_attendance.view');

        $outsource = Outsource::factory()->create();
        $this->makeOutsourceRecord($outsource, '2026-09-10', 'present');
        $this->makeOutsourceRecord($outsource, '2026-09-11', 'late');

        $response = $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/reports/outsource-attendance?from=2026-09-01&to=2026-09-30&status=late');

        $response->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.status', 'late');
    }

    public function test_search_by_name_and_code(): void
    {
        $user = $this->makeUser('USER');
        $user->givePermissionTo('outsource_attendance.view');

        $outsourceA = Outsource::factory()->create(['name' => 'Alpha Worker', 'outsource_code' => 'ALPHA-001']);
        $outsourceB = Outsource::factory()->create(['name' => 'Beta Worker', 'outsource_code' => 'BETA-002']);

        $this->makeOutsourceRecord($outsourceA, '2026-09-10');
        $this->makeOutsourceRecord($outsourceB, '2026-09-10');

        $response = $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/reports/outsource-attendance?from=2026-09-01&to=2026-09-30&search=Alpha');

        $response->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.outsource.name', 'Alpha Worker');
    }

    public function test_city_filter(): void
    {
        $user = $this->makeUser('USER');
        $user->givePermissionTo('outsource_attendance.view');

        $cityA = City::factory()->create();
        $cityB = City::factory()->create();
        $storeA = $this->makeStore('Store A', $cityA->id);
        $storeB = $this->makeStore('Store B', $cityB->id);

        $outsource = Outsource::factory()->create();
        $this->makeActiveAssignment($outsource, $storeA);
        $this->makeActiveAssignment($outsource, $storeB);

        $this->makeOutsourceRecord($outsource, '2026-09-10');

        $response = $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/reports/outsource-attendance?from=2026-09-01&to=2026-09-30&city_id='.$cityA->id);

        $response->assertOk()->assertJsonCount(1, 'data');
    }

    public function test_store_filter(): void
    {
        $user = $this->makeUser('USER');
        $user->givePermissionTo('outsource_attendance.view');

        $city = City::factory()->create();
        $storeA = $this->makeStore('Store A', $city->id);
        $storeB = $this->makeStore('Store B', $city->id);

        $outsource = Outsource::factory()->create();
        $this->makeActiveAssignment($outsource, $storeA);
        $this->makeActiveAssignment($outsource, $storeB);

        $this->makeOutsourceRecord($outsource, '2026-09-10');

        $response = $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/reports/outsource-attendance?from=2026-09-01&to=2026-09-30&store_id='.$storeA->id);

        $response->assertOk()->assertJsonCount(1, 'data');
    }

    // ========================
    // Sorting
    // ========================

    public function test_sort_by_attendance_date(): void
    {
        $user = $this->makeUser('USER');
        $user->givePermissionTo('outsource_attendance.view');

        $outsource = Outsource::factory()->create();
        $this->makeOutsourceRecord($outsource, '2026-09-15');
        $this->makeOutsourceRecord($outsource, '2026-09-05');

        $response = $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/reports/outsource-attendance?from=2026-09-01&to=2026-09-30&sort=attendance_date&direction=asc');

        $response->assertOk();
        $this->assertSame('2026-09-05', $response->json('data.0.attendance_date'));
        $this->assertSame('2026-09-15', $response->json('data.1.attendance_date'));
    }

    public function test_sort_defaults_to_attendance_date_desc(): void
    {
        $user = $this->makeUser('USER');
        $user->givePermissionTo('outsource_attendance.view');

        $outsource = Outsource::factory()->create();
        $this->makeOutsourceRecord($outsource, '2026-09-15');
        $this->makeOutsourceRecord($outsource, '2026-09-05');

        $response = $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/reports/outsource-attendance?from=2026-09-01&to=2026-09-30');

        $response->assertOk();
        $this->assertSame('2026-09-15', $response->json('data.0.attendance_date'));
    }

    // ========================
    // Pagination
    // ========================

    public function test_pagination_metadata(): void
    {
        $user = $this->makeUser('USER');
        $user->givePermissionTo('outsource_attendance.view');

        $outsource = Outsource::factory()->create();
        for ($i = 1; $i <= 5; $i++) {
            $this->makeOutsourceRecord($outsource, '2026-09-'.str_pad((string) $i, 2, '0', STR_PAD_LEFT));
        }

        $response = $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/reports/outsource-attendance?from=2026-09-01&to=2026-09-30&per_page=2');

        $response->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('meta.total', 5)
            ->assertJsonPath('meta.per_page', 2)
            ->assertJsonPath('meta.current_page', 1)
            ->assertJsonPath('meta.last_page', 3);
    }

    public function test_page_two_returns_correct_records(): void
    {
        $user = $this->makeUser('USER');
        $user->givePermissionTo('outsource_attendance.view');

        $outsource = Outsource::factory()->create();
        for ($i = 1; $i <= 5; $i++) {
            $this->makeOutsourceRecord($outsource, '2026-09-'.str_pad((string) $i, 2, '0', STR_PAD_LEFT));
        }

        $response = $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/reports/outsource-attendance?from=2026-09-01&to=2026-09-30&per_page=2&page=2');

        $response->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('meta.current_page', 2);
    }

    // ========================
    // Cross-Midnight
    // ========================

    public function test_cross_midnight_attendance_date_preserved(): void
    {
        $user = $this->makeUser('USER');
        $user->givePermissionTo('outsource_attendance.view');

        $outsource = Outsource::factory()->create();
        $record = $this->makeOutsourceRecord($outsource, '2026-09-14', 'present');

        $record->sessions()->first()->update([
            'check_in_at' => '2026-09-14 22:00:00',
            'check_out_at' => '2026-09-15 06:00:00',
            'duration_minutes' => 480,
            'status' => 'closed',
        ]);

        $response = $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/reports/outsource-attendance?from=2026-09-14&to=2026-09-15');

        $response->assertOk()
            ->assertJsonPath('data.0.attendance_date', '2026-09-14')
            ->assertJsonPath('data.0.check_in_at', '2026-09-14 22:00:00')
            ->assertJsonPath('data.0.check_out_at', '2026-09-15 06:00:00');
    }

    // ========================
    // Relationships
    // ========================

    public function test_report_includes_outsource_and_store(): void
    {
        $user = $this->makeUser('USER');
        $user->givePermissionTo('outsource_attendance.view');

        $city = City::factory()->create(['name' => 'Jakarta']);
        $store = $this->makeStore('Store Jakarta', $city->id);
        $outsource = Outsource::factory()->create(['name' => 'Test Worker', 'outsource_code' => 'TEST-001']);

        $this->makeActiveAssignment($outsource, $store);
        $this->makeOutsourceRecord($outsource, '2026-09-10', 'present', 480);

        $response = $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/reports/outsource-attendance?from=2026-09-01&to=2026-09-30');

        $response->assertOk()
            ->assertJsonPath('data.0.outsource.name', 'Test Worker')
            ->assertJsonPath('data.0.outsource.code', 'TEST-001')
            ->assertJsonPath('data.0.store.name', 'Store Jakarta')
            ->assertJsonPath('data.0.city.name', 'Jakarta');
    }

    // ========================
    // Response Security
    // ========================

    public function test_response_does_not_expose_sensitive_fields(): void
    {
        $user = $this->makeUser('USER');
        $user->givePermissionTo('outsource_attendance.view');

        $outsource = Outsource::factory()->create();
        $this->makeOutsourceRecord($outsource, '2026-09-10');

        $response = $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/reports/outsource-attendance?from=2026-09-01&to=2026-09-30');

        $response->assertOk();
        $this->assertArrayNotHasKey('token_hash', $response->json('data.0'));
        $this->assertArrayNotHasKey('session_token', $response->json('data.0'));
        $this->assertArrayNotHasKey('biometric_data', $response->json('data.0'));
    }

    // ========================
    // Empty Results
    // ========================

    public function test_empty_result_for_valid_range(): void
    {
        $user = $this->makeUser('USER');
        $user->givePermissionTo('outsource_attendance.view');

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/reports/outsource-attendance?from=2026-09-01&to=2026-09-30')
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }
}
