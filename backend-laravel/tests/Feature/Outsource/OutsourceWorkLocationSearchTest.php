<?php

namespace Tests\Feature\Outsource;

use App\Models\User;
use App\Models\WorkLocation;
use App\Models\WorkLocationPin;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OutsourceWorkLocationSearchTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    private function actingViewer(): User
    {
        $user = User::factory()->create();
        $user->assignRole('USER');
        $user->givePermissionTo('outsource_work_location.view');

        return $user;
    }

    public function test_long_address_search_with_plus_code_is_accepted(): void
    {
        $user = $this->actingViewer();

        $store = WorkLocation::factory()->create(['status' => 'active']);
        $address = 'Jl. Raya Sesetan No.18, Sesetan, Denpasar Selatan, Kota Denpasar, Bali 80223 86C7+5X Sesetan, Kota Denpasar, Bali';

        WorkLocationPin::factory()->forLocation($store)->create([
            'name' => 'Sesetan',
            'address' => $address,
            'status' => 'active',
        ]);

        $this->assertGreaterThan(100, strlen($address));
        $this->assertLessThanOrEqual(255, strlen($address));

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/outsource-work-locations?'.http_build_query([
                'search' => $address,
                'status' => 'active',
                'per_page' => 25,
                'sort' => 'cabang',
                'direction' => 'asc',
                'page' => 1,
            ]))
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonCount(1, 'data');
    }

    public function test_search_over_255_characters_returns_friendly_validation_error(): void
    {
        $user = $this->actingViewer();
        $tooLong = str_repeat('a', 256);

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/outsource-work-locations?'.http_build_query([
                'search' => $tooLong,
                'status' => 'active',
            ]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['search'])
            ->assertJsonPath(
                'errors.search.0',
                'Search may not exceed 255 characters. Shorten your query and try again.'
            );
    }
}
