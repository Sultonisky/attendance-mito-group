<?php

namespace Tests\Feature\Outsource;

use App\Models\City;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CreateCityTest extends TestCase
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

    public function test_store_city_requires_auth(): void
    {
        $this->postJson('/api/v1/outsource-work-locations/cities', ['name' => 'Jakarta'])
            ->assertUnauthorized();
    }

    public function test_store_city_requires_permission(): void
    {
        $user = $this->makeUser('USER');

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/outsource-work-locations/cities', ['name' => 'Jakarta'])
            ->assertForbidden();
    }

    public function test_user_with_permission_can_create_city(): void
    {
        $user = $this->makeUser('USER');
        $user->givePermissionTo('outsource_work_location.create');

        $response = $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/outsource-work-locations/cities', ['name' => '  Balikpapan  ']);

        $response->assertCreated()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.name', 'Balikpapan');

        $this->assertNotNull($response->json('data.id'));
        $this->assertNotEmpty($response->json('data.code'));
        $this->assertDatabaseHas('cities', [
            'name' => 'Balikpapan',
            'status' => 'active',
        ]);
    }

    public function test_duplicate_active_city_name_is_rejected(): void
    {
        City::factory()->create(['name' => 'Jakarta', 'status' => 'active']);

        $user = $this->makeUser('USER');
        $user->givePermissionTo('outsource_work_location.create');

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/outsource-work-locations/cities', ['name' => 'jakarta'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['name']);
    }

    public function test_soft_deleted_city_is_restored(): void
    {
        $city = City::factory()->create(['name' => 'Samarinda', 'status' => 'inactive']);
        $city->delete();

        $user = $this->makeUser('USER');
        $user->givePermissionTo('outsource_work_location.create');

        $response = $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/outsource-work-locations/cities', ['name' => 'Samarinda']);

        $response->assertCreated()
            ->assertJsonPath('data.id', $city->id)
            ->assertJsonPath('data.name', 'Samarinda');

        $this->assertDatabaseHas('cities', [
            'id' => $city->id,
            'name' => 'Samarinda',
            'status' => 'active',
            'deleted_at' => null,
        ]);
    }
}
