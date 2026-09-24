<?php

namespace Tests\Feature\Outsource;

use App\Models\AttendanceEvent;
use App\Models\AttendanceRecord;
use App\Models\AttendanceSession;
use App\Models\Employee;
use App\Models\Outsource;
use App\Models\OutsourceStoreAssignment;
use App\Models\User;
use App\Models\WorkLocation;
use App\Models\WorkLocationPin;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OutsourceAttendanceAdminCrudTest extends TestCase
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

    private function makeStore(float $lat = -6.2, float $lng = 106.8): WorkLocation
    {
        $store = WorkLocation::factory()->create([
            'latitude' => $lat,
            'longitude' => $lng,
            'radius_meters' => 150,
            'status' => 'active',
        ]);

        WorkLocationPin::factory()
            ->forLocation($store)
            ->atCoordinates($lat, $lng, 150)
            ->create([
                'name' => 'Pin Demo',
                'address' => 'Jl. Demo No. 1',
                'status' => 'active',
            ]);

        return $store;
    }

    private function defaultPin(WorkLocation $store): WorkLocationPin
    {
        return WorkLocationPin::query()
            ->where('work_location_id', $store->id)
            ->where('status', 'active')
            ->orderBy('id')
            ->firstOrFail();
    }

    private function makeActiveAssignment(Outsource $outsource, WorkLocation $store): OutsourceStoreAssignment
    {
        return OutsourceStoreAssignment::factory()
            ->forOutsource($outsource)
            ->forStore($store)
            ->create(['status' => 'active']);
    }

    private function adminWithCrud(): User
    {
        $user = $this->makeUser('USER');
        $user->givePermissionTo([
            'outsource_attendance.view',
            'outsource_attendance.create',
            'outsource_attendance.update',
            'outsource_attendance.void',
        ]);

        return $user;
    }

    public function test_create_requires_auth(): void
    {
        $this->postJson('/api/v1/outsource-attendance', [])
            ->assertUnauthorized();
    }

    public function test_create_requires_permission(): void
    {
        $user = $this->makeUser('USER');
        $user->givePermissionTo('outsource_attendance.view');

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/outsource-attendance', [])
            ->assertForbidden();
    }

    public function test_update_requires_permission(): void
    {
        $user = $this->makeUser('USER');
        $user->givePermissionTo('outsource_attendance.view');

        $outsource = Outsource::factory()->create(['status' => 'active']);
        $record = AttendanceRecord::create([
            'outsource_id' => $outsource->id,
            'attendable_type' => 'outsource',
            'attendance_date' => '2026-09-10',
            'status' => 'incomplete',
        ]);

        $this->actingAs($user, 'sanctum')
            ->putJson('/api/v1/outsource-attendance/'.$record->id, [
                'pin_id' => 1,
                'check_in_at' => '2026-09-10 08:00',
            ])
            ->assertForbidden();
    }

    public function test_admin_can_create_present_record(): void
    {
        $admin = $this->adminWithCrud();
        $store = $this->makeStore();
        $pin = $this->defaultPin($store);
        $outsource = Outsource::factory()->create(['status' => 'active']);
        $this->makeActiveAssignment($outsource, $store);

        $response = $this->actingAs($admin, 'sanctum')
            ->postJson('/api/v1/outsource-attendance', [
                'outsource_id' => $outsource->id,
                'attendance_date' => '2026-09-10',
                'pin_id' => $pin->id,
                'check_in_at' => '2026-09-10 08:30',
                'check_out_at' => '2026-09-10 17:00',
            ]);

        $response->assertCreated()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.status', 'present')
            ->assertJsonPath('data.duration_minutes', 510)
            ->assertJsonPath('data.pin.id', $pin->id);

        $this->assertDatabaseHas('attendance_records', [
            'outsource_id' => $outsource->id,
            'status' => 'present',
        ]);
        $this->assertTrue(
            AttendanceRecord::query()
                ->where('outsource_id', $outsource->id)
                ->whereDate('attendance_date', '2026-09-10')
                ->where('status', 'present')
                ->exists()
        );
        $this->assertDatabaseHas('attendance_events', [
            'outsource_id' => $outsource->id,
            'work_location_pin_id' => $pin->id,
            'event_type' => 'check_in',
            'source' => 'admin',
        ]);
    }

    public function test_admin_can_create_incomplete_record(): void
    {
        $admin = $this->adminWithCrud();
        $store = $this->makeStore();
        $pin = $this->defaultPin($store);
        $outsource = Outsource::factory()->create(['status' => 'active']);
        $this->makeActiveAssignment($outsource, $store);

        $this->actingAs($admin, 'sanctum')
            ->postJson('/api/v1/outsource-attendance', [
                'outsource_id' => $outsource->id,
                'attendance_date' => '2026-09-11',
                'pin_id' => $pin->id,
                'check_in_at' => '2026-09-11 08:30',
                'check_out_at' => null,
            ])
            ->assertCreated()
            ->assertJsonPath('data.status', 'incomplete')
            ->assertJsonPath('data.check_out_at', null);
    }

    public function test_admin_can_create_overnight_record(): void
    {
        $admin = $this->adminWithCrud();
        $store = $this->makeStore();
        $pin = $this->defaultPin($store);
        $outsource = Outsource::factory()->create(['status' => 'active']);
        $this->makeActiveAssignment($outsource, $store);

        $response = $this->actingAs($admin, 'sanctum')
            ->postJson('/api/v1/outsource-attendance', [
                'outsource_id' => $outsource->id,
                'attendance_date' => '2026-09-12',
                'pin_id' => $pin->id,
                'check_in_at' => '2026-09-12 21:00',
                'check_out_at' => '2026-09-13 07:00',
            ]);

        $response->assertCreated()
            ->assertJsonPath('data.status', 'present')
            ->assertJsonPath('data.duration_minutes', 600)
            ->assertJsonPath('data.check_in_at', '2026-09-12T21:00:00+07:00')
            ->assertJsonPath('data.check_out_at', '2026-09-13T07:00:00+07:00');
    }

    public function test_create_rejects_duplicate_date(): void
    {
        $admin = $this->adminWithCrud();
        $store = $this->makeStore();
        $pin = $this->defaultPin($store);
        $outsource = Outsource::factory()->create(['status' => 'active']);
        $this->makeActiveAssignment($outsource, $store);

        $payload = [
            'outsource_id' => $outsource->id,
            'attendance_date' => '2026-09-14',
            'pin_id' => $pin->id,
            'check_in_at' => '2026-09-14 08:00',
            'check_out_at' => '2026-09-14 17:00',
        ];

        $this->actingAs($admin, 'sanctum')
            ->postJson('/api/v1/outsource-attendance', $payload)
            ->assertCreated();

        $this->actingAs($admin, 'sanctum')
            ->postJson('/api/v1/outsource-attendance', $payload)
            ->assertStatus(422)
            ->assertJsonPath('message', 'Attendance already exists for this outsource on that date.');
    }

    public function test_create_rejects_invalid_pin(): void
    {
        $admin = $this->adminWithCrud();
        $storeA = $this->makeStore(-6.2, 106.8);
        $storeB = $this->makeStore(-6.3, 106.9);
        $pinB = $this->defaultPin($storeB);
        $outsource = Outsource::factory()->create(['status' => 'active']);
        $this->makeActiveAssignment($outsource, $storeA);

        $this->actingAs($admin, 'sanctum')
            ->postJson('/api/v1/outsource-attendance', [
                'outsource_id' => $outsource->id,
                'attendance_date' => '2026-09-15',
                'pin_id' => $pinB->id,
                'check_in_at' => '2026-09-15 08:00',
            ])
            ->assertStatus(422);
    }

    public function test_admin_can_update_times_and_pin(): void
    {
        $admin = $this->adminWithCrud();
        $store = $this->makeStore();
        $pin = $this->defaultPin($store);
        $pin2 = WorkLocationPin::factory()
            ->forLocation($store)
            ->atCoordinates(-6.201, 106.801, 120)
            ->create(['name' => 'Pin 2', 'address' => 'Dock', 'status' => 'active']);

        $outsource = Outsource::factory()->create(['status' => 'active']);
        $this->makeActiveAssignment($outsource, $store);

        $create = $this->actingAs($admin, 'sanctum')
            ->postJson('/api/v1/outsource-attendance', [
                'outsource_id' => $outsource->id,
                'attendance_date' => '2026-09-16',
                'pin_id' => $pin->id,
                'check_in_at' => '2026-09-16 08:00',
            ])
            ->assertCreated();

        $id = (int) $create->json('data.attendance_id');

        $this->actingAs($admin, 'sanctum')
            ->putJson('/api/v1/outsource-attendance/'.$id, [
                'pin_id' => $pin2->id,
                'check_in_at' => '2026-09-16 09:00',
                'check_out_at' => '2026-09-16 18:00',
            ])
            ->assertOk()
            ->assertJsonPath('data.status', 'present')
            ->assertJsonPath('data.duration_minutes', 540)
            ->assertJsonPath('data.pin.id', $pin2->id)
            ->assertJsonPath('data.check_in_at', '2026-09-16T09:00:00+07:00');

        $this->assertSame(1, AttendanceSession::query()->where('attendance_record_id', $id)->count());
        $this->assertSame(2, AttendanceEvent::query()->where('attendance_id', $id)->count());
    }

    public function test_update_rejects_non_outsource_record(): void
    {
        $admin = $this->adminWithCrud();
        $employee = Employee::factory()->create();
        $store = $this->makeStore();
        $pin = $this->defaultPin($store);

        $record = AttendanceRecord::create([
            'employee_id' => $employee->id,
            'attendable_type' => 'employee',
            'attendance_date' => '2026-09-17',
            'status' => 'present',
        ]);

        $this->actingAs($admin, 'sanctum')
            ->putJson('/api/v1/outsource-attendance/'.$record->id, [
                'pin_id' => $pin->id,
                'check_in_at' => '2026-09-17 08:00',
                'check_out_at' => '2026-09-17 17:00',
            ])
            ->assertStatus(422)
            ->assertJsonPath('message', 'Not an outsource attendance record.');
    }

    public function test_report_includes_pin_id_for_edit(): void
    {
        $admin = $this->adminWithCrud();
        $store = $this->makeStore();
        $pin = $this->defaultPin($store);
        $outsource = Outsource::factory()->create(['status' => 'active']);
        $this->makeActiveAssignment($outsource, $store);

        $this->actingAs($admin, 'sanctum')
            ->postJson('/api/v1/outsource-attendance', [
                'outsource_id' => $outsource->id,
                'attendance_date' => '2026-09-18',
                'pin_id' => $pin->id,
                'check_in_at' => '2026-09-18 08:30',
                'check_out_at' => '2026-09-18 17:00',
            ])
            ->assertCreated();

        $this->actingAs($admin, 'sanctum')
            ->getJson('/api/v1/reports/outsource-attendance?from=2026-09-01&to=2026-09-30')
            ->assertOk()
            ->assertJsonPath('data.0.pin.id', $pin->id)
            ->assertJsonPath('data.0.pin.address', 'Jl. Demo No. 1');
    }
}
