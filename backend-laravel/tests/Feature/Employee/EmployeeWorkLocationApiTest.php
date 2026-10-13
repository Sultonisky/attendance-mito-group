<?php

namespace Tests\Feature\Employee;

use App\Enums\WorkAreaType;
use App\Models\City;
use App\Models\EmployeeWorkLocation;
use App\Models\User;
use App\Models\WorkLocation;
use Database\Seeders\EmployeeWorkLocationSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EmployeeWorkLocationApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    private function makeUser(string $role = 'USER'): User
    {
        $user = User::factory()->create();
        $user->assignRole($role);

        return $user;
    }

    private function admin(): User
    {
        return $this->makeUser('ADMIN');
    }

    public function test_index_requires_auth(): void
    {
        $this->getJson('/api/v1/employee-work-locations')->assertUnauthorized();
    }

    public function test_plain_user_cannot_view(): void
    {
        $this->actingAs($this->makeUser('USER'), 'sanctum')
            ->getJson('/api/v1/employee-work-locations')
            ->assertForbidden();
    }

    public function test_admin_can_create_location(): void
    {
        $response = $this->actingAs($this->admin(), 'sanctum')
            ->postJson('/api/v1/employee-work-locations', [
                'name' => 'Head Office Jakarta',
                'city' => 'Jakarta',
                'area_type' => 'head_office',
                'address' => 'Jl. Sudirman',
                'latitude' => -6.08831825073565,
                'longitude' => 106.74385095344795,
            ]);

        $response->assertCreated()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.area_type', 'head_office')
            ->assertJsonPath('data.area_type_label', 'Head Office')
            ->assertJsonPath('data.radius_meters', 150)
            ->assertJsonPath('data.status', 'active');

        $this->assertEqualsWithDelta(-6.0883183, $response->json('data.latitude'), 0.000001);
        $this->assertEqualsWithDelta(106.743851, $response->json('data.longitude'), 0.000001);

        $this->assertMatchesRegularExpression('/^EWL-[0-9a-f]{12}$/', $response->json('data.code'));
        $this->assertDatabaseHas('employee_work_locations', ['name' => 'Head Office Jakarta', 'city' => 'Jakarta']);
        $this->assertDatabaseHas('audit_logs', ['action' => 'employee_work_location.created']);
    }

    public function test_create_generates_unique_codes_and_ignores_client_code(): void
    {
        $admin = $this->admin();
        $payload = [
            'code' => 'CLIENT-CODE',
            'name' => 'Cabang Bandung',
            'city' => 'Bandung',
            'area_type' => 'branch',
            'latitude' => -6.9,
            'longitude' => 107.6,
        ];

        $first = $this->actingAs($admin, 'sanctum')
            ->postJson('/api/v1/employee-work-locations', $payload)
            ->assertCreated()
            ->json('data.code');
        $second = $this->actingAs($admin, 'sanctum')
            ->postJson('/api/v1/employee-work-locations', $payload)
            ->assertCreated()
            ->json('data.code');

        $this->assertNotSame('CLIENT-CODE', $first);
        $this->assertNotSame($first, $second);
    }

    public function test_create_rejects_invalid_area_type_and_missing_coordinates(): void
    {
        $this->actingAs($this->admin(), 'sanctum')
            ->postJson('/api/v1/employee-work-locations', [
                'name' => 'X',
                'city' => 'Bandung',
                'area_type' => 'warehouse',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['area_type', 'latitude', 'longitude']);
    }

    public function test_create_rejects_out_of_range_coordinates_and_radius(): void
    {
        $this->actingAs($this->admin(), 'sanctum')
            ->postJson('/api/v1/employee-work-locations', [
                'name' => 'X',
                'city' => 'Bandung',
                'area_type' => 'branch',
                'latitude' => -91,
                'longitude' => 181,
                'radius_meters' => 0,
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['latitude', 'longitude', 'radius_meters']);
    }

    public function test_index_filters_by_area_type_and_city(): void
    {
        EmployeeWorkLocation::factory()->areaType(WorkAreaType::Factory)->create(['city' => 'Bekasi']);
        EmployeeWorkLocation::factory()->areaType(WorkAreaType::Branch)->create(['city' => 'Bandung']);
        EmployeeWorkLocation::factory()->areaType(WorkAreaType::Branch)->create(['city' => 'Surabaya']);

        $admin = $this->admin();

        $this->actingAs($admin, 'sanctum')
            ->getJson('/api/v1/employee-work-locations?area_type=branch')
            ->assertOk()
            ->assertJsonPath('meta.total', 2);

        $this->actingAs($admin, 'sanctum')
            ->getJson('/api/v1/employee-work-locations?city=Bekasi')
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.area_type', 'factory');

        $this->actingAs($admin, 'sanctum')
            ->getJson('/api/v1/employee-work-locations/cities')
            ->assertOk()
            ->assertJsonPath('data', ['Bandung', 'Bekasi', 'Surabaya']);
    }

    public function test_admin_can_update_and_delete(): void
    {
        $admin = $this->admin();
        $location = EmployeeWorkLocation::factory()->create(['code' => 'CBG-1']);

        $this->actingAs($admin, 'sanctum')
            ->putJson('/api/v1/employee-work-locations/'.$location->id, [
                'code' => 'HACKED',
                'name' => 'Pabrik Baru',
                'city' => 'Karawang',
                'area_type' => 'factory',
                'latitude' => -6.3,
                'longitude' => 107.3,
                'radius_meters' => 500,
                'status' => 'inactive',
            ])
            ->assertOk()
            ->assertJsonPath('data.city', 'Karawang')
            ->assertJsonPath('data.area_type', 'factory')
            ->assertJsonPath('data.latitude', -6.3)
            ->assertJsonPath('data.longitude', 107.3)
            ->assertJsonPath('data.radius_meters', 500)
            ->assertJsonPath('data.code', 'CBG-1')
            ->assertJsonPath('data.status', 'inactive');

        $this->actingAs($admin, 'sanctum')
            ->deleteJson('/api/v1/employee-work-locations/'.$location->id)
            ->assertOk();

        $this->assertSoftDeleted('employee_work_locations', ['id' => $location->id]);
        $this->assertDatabaseHas('audit_logs', ['action' => 'employee_work_location.deleted']);
    }

    public function test_employee_locations_do_not_leak_into_outsource_public_dropdowns(): void
    {
        EmployeeWorkLocation::factory()->create(['city' => 'Jakarta']);

        $this->getJson('/api/v1/outsource/cities')
            ->assertOk()
            ->assertJsonCount(0, 'data');
        $this->getJson('/api/v1/outsource/stores')
            ->assertOk()
            ->assertJsonCount(0, 'data');

        $this->assertSame(0, City::count());
        $this->assertSame(0, WorkLocation::count());
    }

    public function test_seeder_is_local_only(): void
    {
        $this->seed(EmployeeWorkLocationSeeder::class);

        $this->assertSame(0, EmployeeWorkLocation::count());
    }
}
