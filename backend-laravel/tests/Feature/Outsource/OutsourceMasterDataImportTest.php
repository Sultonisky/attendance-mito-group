<?php

namespace Tests\Feature\Outsource;

use App\Models\City;
use App\Models\Outsource;
use App\Models\OutsourceStoreAssignment;
use App\Models\WorkLocation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use RuntimeException;
use Tests\TestCase;

class OutsourceMasterDataImportTest extends TestCase
{
    use RefreshDatabase;

    private function fixturePath(string $name): string
    {
        return __DIR__.'/../../Fixtures/'.$name;
    }

    public function test_valid_import_creates_master_data(): void
    {
        $path = $this->fixturePath('outsource_sample.csv');

        $result = app(\App\Services\Import\OutsourceMasterDataImportService::class)->import($path);

        $this->assertSame(2, $result['cities']['created']);
        $this->assertSame(8, $result['stores']['created']);
        $this->assertSame(8, $result['outsources']['created']);
        $this->assertSame(8, $result['assignments']['created']);

        $this->assertDatabaseHas('cities', ['name' => 'BALIKPAPAN']);
        $this->assertDatabaseHas('work_locations', ['name' => 'NUANSA BALIKPAPAN', 'city_id' => City::where('name', 'BALIKPAPAN')->value('id')]);
        $this->assertDatabaseHas('outsources', ['name' => 'Devi Isvaradilla Agrully']);
        $this->assertDatabaseHas('outsource_store_assignments', [
            'outsource_id' => Outsource::where('name', 'Devi Isvaradilla Agrully')->value('id'),
            'store_id' => WorkLocation::where('name', 'NUANSA BALIKPAPAN')->value('id'),
        ]);
    }

    public function test_duplicate_city_and_store_are_reused(): void
    {
        $path = $this->fixturePath('outsource_sample.csv');

        app(\App\Services\Import\OutsourceMasterDataImportService::class)->import($path);
        $result = app(\App\Services\Import\OutsourceMasterDataImportService::class)->import($path);

        $this->assertSame(0, $result['cities']['created']);
        $this->assertSame(0, $result['stores']['created']);
        $this->assertSame(0, $result['outsources']['created']);
        $this->assertSame(0, $result['assignments']['created']);

        $this->assertSame(2, City::count());
        $this->assertSame(8, WorkLocation::count());
        $this->assertSame(8, Outsource::count());
        $this->assertSame(8, OutsourceStoreAssignment::count());
    }

    public function test_invalid_row_is_rejected_for_safe_import(): void
    {
        $path = $this->fixturePath('outsource_invalid.csv');

        $this->expectException(RuntimeException::class);

        app(\App\Services\Import\OutsourceMasterDataImportService::class)->import($path);
    }

    public function test_dry_run_does_not_persist_changes(): void
    {
        $path = $this->fixturePath('outsource_sample.csv');

        $result = app(\App\Services\Import\OutsourceMasterDataImportService::class)->import($path, true);

        $this->assertTrue($result['dry_run']);
        $this->assertSame(0, City::count());
        $this->assertSame(0, WorkLocation::count());
        $this->assertSame(0, Outsource::count());
        $this->assertSame(0, OutsourceStoreAssignment::count());
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

        // Import once so rows exist, then force an impossible threshold via a
        // second call that only reuses existing rows — still count >= 8.
        Artisan::call('outsource:import', ['file' => $path]);

        $exitCode = Artisan::call('outsource:import', [
            'file' => $path,
            '--require-min' => 9999,
        ]);

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('Import guard failed', Artisan::output());
    }
}
