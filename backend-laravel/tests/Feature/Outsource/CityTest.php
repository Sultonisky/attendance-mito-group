<?php

namespace Tests\Feature\Outsource;

use App\Models\City;
use App\Models\WorkLocation;
use App\Services\Import\OutsourceMasterDataImportService;
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

    public function test_imported_city_without_code_gets_generated_code(): void
    {
        $service = new OutsourceMasterDataImportService();
        $method = new \ReflectionMethod($service, 'resolveCity');
        $method->setAccessible(true);

        $city = $method->invoke($service, 'Bandung');

        $this->assertNotNull($city->code);
        $this->assertNotSame('', trim((string) $city->code));
        $this->assertMatchesRegularExpression('/^(CITY|BANDUNG)/i', (string) $city->code);
    }

    public function test_city_has_work_locations(): void
    {
        $city = City::factory()->create();
        WorkLocation::factory()->create(['city_id' => $city->id]);

        $this->assertCount(1, $city->workLocations);
    }
}
