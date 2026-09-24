<?php

namespace Tests\Feature\Outsource;

use App\Models\City;
use App\Models\Outsource;
use App\Models\OutsourceStoreAssignment;
use App\Models\WorkLocation;
use App\Models\WorkLocationPin;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use RuntimeException;
use Tests\TestCase;

class OutsourceMasterDataImportTest extends TestCase
{
    use RefreshDatabase;

    private function fixturePath(string $name): string
    {
        return __DIR__.'/../../Fixtures/'.$name;
    }

    private function writeJson(array $rows): string
    {
        $path = storage_path('framework/testing/outsource-unified.json');
        File::ensureDirectoryExists(dirname($path));
        File::put($path, json_encode($rows, JSON_THROW_ON_ERROR));

        return $path;
    }

    public function test_json_import_creates_cabang_per_city_with_pins(): void
    {
        $path = $this->writeJson([
            [
                'city' => 'BANDUNG',
                'store' => 'YOGYA RIAU JUNCTION',
                'employee' => 'Wiwit Pujiyanti',
                'pin_name' => 'Riau 1',
                'address' => 'Jl. Riau 1',
                'lat' => -6.907563,
                'lon' => 107.6117344,
                'source' => 'excel_normalized',
                'is_fallback' => false,
            ],
            [
                'city' => 'BANDUNG',
                'store' => 'YOGYA RIAU JUNCTION',
                'employee' => 'Wiwit Pujiyanti',
                'pin_name' => 'Riau 2',
                'address' => 'Jl. Riau 2',
                'lat' => -6.9080000,
                'lon' => 107.6120000,
                'source' => 'excel_normalized',
                'is_fallback' => false,
            ],
            [
                'city' => 'BANDUNG',
                'store' => 'HARTONO',
                'employee' => 'Deni Lindiansah',
                'pin_name' => 'Hartono',
                'address' => 'Jl. Hartono',
                'lat' => -6.9100000,
                'lon' => 107.6100000,
                'source' => 'excel_normalized',
                'is_fallback' => false,
            ],
            [
                'city' => 'BANGKA',
                'store' => 'MITO - SALES B2B',
                'employee' => 'Martin Agustian',
                'pin_name' => 'Wilayah Bangka',
                'address' => 'Area kerja Bangka',
                'lat' => -2.17,
                'lon' => 106.12,
                'radius_meters' => 80000,
                'source' => 'manual_area',
                'is_fallback' => false,
            ],
        ]);

        $result = app(\App\Services\Import\OutsourceMasterDataImportService::class)->import($path);

        $this->assertSame(2, $result['cities']['created']);
        $this->assertSame(2, $result['cabangs']['created']);
        $this->assertSame(3, $result['outsources']['created']);
        $this->assertSame(4, $result['pins_created']);

        $bandung = City::where('name', 'BANDUNG')->firstOrFail();
        $cabang = WorkLocation::where('city_id', $bandung->id)->where('name', 'BANDUNG')->firstOrFail();
        $this->assertSame(3, WorkLocationPin::where('work_location_id', $cabang->id)->count());

        $defaultPin = WorkLocationPin::where('work_location_id', $cabang->id)->where('name', 'Riau 1')->firstOrFail();
        $this->assertSame(150.0, (float) $defaultPin->radius_meters);

        // Toko names must NOT become cabangs.
        $this->assertSame(0, WorkLocation::where('name', 'YOGYA RIAU JUNCTION')->count());

        $wiwit = Outsource::where('name', 'Wiwit Pujiyanti')->firstOrFail();
        $assignment = OutsourceStoreAssignment::where('outsource_id', $wiwit->id)
            ->where('store_id', $cabang->id)
            ->firstOrFail();
        $this->assertCount(2, $assignment->pins);

        $martinPin = WorkLocationPin::where('name', 'Wilayah Bangka')->firstOrFail();
        $this->assertSame(80000.0, (float) $martinPin->radius_meters);
        $this->assertSame(80000.0, $martinPin->effectiveRadiusMeters());

        $martin = Outsource::where('name', 'Martin Agustian')->firstOrFail();
        $bangkaCabang = WorkLocation::where('name', 'BANGKA')->firstOrFail();
        $martinAssignment = OutsourceStoreAssignment::where('outsource_id', $martin->id)
            ->where('store_id', $bangkaCabang->id)
            ->firstOrFail();
        $this->assertCount(1, $martinAssignment->pins);
        $this->assertTrue($martinAssignment->pins->contains('id', $martinPin->id));
    }

    public function test_json_import_uses_employee_id_and_password(): void
    {
        $path = $this->writeJson([
            [
                'city' => 'SAMARINDA',
                'store' => 'MAMASUKA',
                'employee' => 'Abel Saskia Putri',
                'employee_id' => 'DM20260001',
                'password' => '123456',
                'pin_name' => 'MAMASUKA',
                'address' => 'Jl. P Antasari No.38',
                'lat' => -0.492476,
                'lon' => 117.1273092,
                'source' => 'excel_normalized',
                'is_fallback' => false,
            ],
        ]);

        $result = app(\App\Services\Import\OutsourceMasterDataImportService::class)->import($path);

        $this->assertSame(1, $result['outsources']['created']);

        $person = Outsource::where('outsource_code', 'DM20260001')->firstOrFail();
        $this->assertSame('Abel Saskia Putri', $person->name);
        $this->assertTrue(\Illuminate\Support\Facades\Hash::check('123456', $person->password));
    }

    public function test_legacy_csv_creates_cabang_per_city_not_per_toko(): void
    {
        $path = $this->fixturePath('outsource_sample.csv');

        $result = app(\App\Services\Import\OutsourceMasterDataImportService::class)->import($path);

        $this->assertSame(2, $result['cities']['created']);
        $this->assertSame(2, $result['cabangs']['created']);
        $this->assertSame(8, $result['outsources']['created']);
        $this->assertSame(8, $result['assignments']['created']);

        $this->assertDatabaseHas('cities', ['name' => 'BALIKPAPAN']);
        $this->assertDatabaseHas('work_locations', [
            'name' => 'BALIKPAPAN',
            'city_id' => City::where('name', 'BALIKPAPAN')->value('id'),
        ]);
        $this->assertDatabaseMissing('work_locations', ['name' => 'NUANSA BALIKPAPAN']);
        $this->assertDatabaseHas('outsources', ['name' => 'Devi Isvaradilla Agrully']);
        $this->assertDatabaseHas('outsource_store_assignments', [
            'outsource_id' => Outsource::where('name', 'Devi Isvaradilla Agrully')->value('id'),
            'store_id' => WorkLocation::where('name', 'BALIKPAPAN')->value('id'),
        ]);
    }

    public function test_duplicate_import_is_idempotent(): void
    {
        $path = $this->fixturePath('outsource_sample.csv');

        app(\App\Services\Import\OutsourceMasterDataImportService::class)->import($path);
        $result = app(\App\Services\Import\OutsourceMasterDataImportService::class)->import($path);

        $this->assertSame(0, $result['cities']['created']);
        $this->assertSame(0, $result['cabangs']['created']);
        $this->assertSame(0, $result['outsources']['created']);
        $this->assertSame(0, $result['assignments']['created']);

        $this->assertSame(2, City::count());
        $this->assertSame(2, WorkLocation::count());
        $this->assertSame(8, Outsource::count());
        $this->assertSame(8, OutsourceStoreAssignment::count());
    }

    public function test_invalid_row_is_rejected_for_safe_import(): void
    {
        $path = $this->fixturePath('outsource_invalid.csv');

        $this->expectException(RuntimeException::class);

        app(\App\Services\Import\OutsourceMasterDataImportService::class)->import($path, false, 150, false);
    }

    public function test_dry_run_does_not_persist_changes(): void
    {
        $path = $this->fixturePath('outsource_sample.csv');

        $result = app(\App\Services\Import\OutsourceMasterDataImportService::class)->import($path, true);

        $this->assertTrue($result['dry_run']);
        $this->assertSame(0, City::count());
        $this->assertSame(0, WorkLocation::count());
        $this->assertSame(0, Outsource::count());
    }

    public function test_artisan_command_exists_and_supports_dry_run(): void
    {
        $path = $this->fixturePath('outsource_sample.csv');

        $exitCode = Artisan::call('outsource:import', ['file' => $path, '--dry-run' => true]);

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('DRY RUN', Artisan::output());
    }

    public function test_require_min_passes_when_rows_imported(): void
    {
        $path = $this->fixturePath('outsource_sample.csv');

        $exitCode = Artisan::call('outsource:import', [
            'file' => $path,
            '--require-min' => 1,
        ]);

        $this->assertSame(0, $exitCode);
        $this->assertDatabaseCount('outsources', 8);
    }

    public function test_require_min_fails_when_threshold_not_met(): void
    {
        $path = $this->fixturePath('outsource_sample.csv');

        Artisan::call('outsource:import', ['file' => $path]);

        $exitCode = Artisan::call('outsource:import', [
            'file' => $path,
            '--require-min' => 9999,
        ]);

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('Import guard failed', Artisan::output());
    }
}
