<?php

namespace Tests\Feature\Outsource;

use App\Models\City;
use App\Models\WorkLocation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CityTest extends TestCase
{
    use RefreshDatabase;

    public function test_city_can_be_created(): void
    {
        $city = City::factory()->create([
            'name' => 'Jakarta',
            'code' => 'JKT',
            'status' => 'active',
        ]);

        $this->assertDatabaseHas('cities', [
            'name' => 'Jakarta',
            'code' => 'JKT',
            'status' => 'active',
        ]);
    }

    public function test_city_has_work_locations(): void
    {
        $city = City::factory()->create();
        WorkLocation::factory()->create(['city_id' => $city->id]);

        $this->assertCount(1, $city->workLocations);
    }
}
