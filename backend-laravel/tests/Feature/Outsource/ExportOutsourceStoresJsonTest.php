<?php

namespace Tests\Feature\Outsource;

use App\Models\Outsource;
use App\Models\OutsourceStoreAssignment;
use App\Models\WorkLocationPin;
use App\Services\Export\OutsourceStoresJsonExporter;
use App\Services\Import\OutsourceMasterDataImportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ExportOutsourceStoresJsonTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');

        $path = storage_path('framework/testing/export-seed.json');
        File::ensureDirectoryExists(dirname($path));
        File::put($path, json_encode([
            [
                'city' => 'BALIKPAPAN', 'store' => 'NUANSA', 'employee' => 'Ana Explicit',
                'employee_id' => 'DM001', 'password' => '111111', 'pin_name' => 'Jl. Mayjend Sutoyo No.62',
                'address' => 'Jl. Mayjend Sutoyo No.62', 'lat' => -1.2749625, 'lon' => 116.8461094,
            ],
            [
                'city' => 'BALIKPAPAN', 'store' => 'PLAZA', 'employee' => 'Budi All Pins',
                'employee_id' => 'DM002', 'password' => '222222', 'pin_name' => 'Plaza',
                'address' => 'Jl. Plaza', 'lat' => -1.27, 'lon' => 116.84,
            ],
        ], JSON_THROW_ON_ERROR));

        app(OutsourceMasterDataImportService::class)->import($path);

        // Budi: empty allowlist => all active BALIKPAPAN pins.
        OutsourceStoreAssignment::where('outsource_id', Outsource::where('outsource_code', 'DM002')->value('id'))
            ->firstOrFail()
            ->pins()
            ->sync([]);
    }

    private function exporter(): OutsourceStoresJsonExporter
    {
        return app(OutsourceStoresJsonExporter::class);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function activeRows(): array
    {
        return json_decode(Storage::disk('local')->get(OutsourceStoresJsonExporter::ACTIVE_PATH), true, 512, JSON_THROW_ON_ERROR);
    }

    public function test_export_writes_current_db_state_without_pins_codes(): void
    {
        WorkLocationPin::where('name', 'Jl. Mayjend Sutoyo No.62')->firstOrFail()->update(['radius_meters' => 550]);

        $result = $this->exporter()->export();

        $this->assertSame(OutsourceStoresJsonExporter::STATUS_WRITTEN, $result['status']);
        $this->assertNull($result['backup']);
        $this->assertSame(2, $result['persons']);

        $rows = collect($this->activeRows());
        $ana = $rows->where('employee_id', 'DM001')->values();
        $this->assertCount(1, $ana);
        $this->assertSame('pin', $ana[0]['pin_scope']);
        $this->assertEquals(550, $ana[0]['radius_meters']);
        $this->assertArrayNotHasKey('password', $ana[0]);

        // Empty allowlist exports every active cabang pin.
        $budi = $rows->where('employee_id', 'DM002');
        $this->assertCount(2, $budi);
        $this->assertSame(['all_cabang_pins'], $budi->pluck('pin_scope')->unique()->values()->all());
    }

    public function test_rerun_without_db_changes_keeps_file_and_creates_no_backup(): void
    {
        $this->exporter()->export();
        $result = $this->exporter()->export();

        $this->assertSame(OutsourceStoresJsonExporter::STATUS_UNCHANGED, $result['status']);
        $this->assertSame([], Storage::disk('local')->files(OutsourceStoresJsonExporter::BACKUP_DIR));
    }

    public function test_db_change_rotates_previous_file_into_backups(): void
    {
        $this->exporter()->export();
        $firstVersion = Storage::disk('local')->get(OutsourceStoresJsonExporter::ACTIVE_PATH);

        WorkLocationPin::where('name', 'Plaza')->firstOrFail()->update(['latitude' => -1.28, 'longitude' => 116.85]);
        $result = $this->exporter()->export();

        $this->assertSame(OutsourceStoresJsonExporter::STATUS_WRITTEN, $result['status']);
        $backups = Storage::disk('local')->files(OutsourceStoresJsonExporter::BACKUP_DIR);
        $this->assertCount(1, $backups);
        $this->assertSame($firstVersion, Storage::disk('local')->get($backups[0]));

        $plaza = collect($this->activeRows())->firstWhere('pin_name', 'Plaza');
        $this->assertEquals(-1.28, $plaza['lat']);
    }

    public function test_excludes_deleted_persons_and_honors_inactive_only_allowlist(): void
    {
        Outsource::where('outsource_code', 'DM002')->firstOrFail()->delete();
        WorkLocationPin::where('name', 'Jl. Mayjend Sutoyo No.62')->firstOrFail()->update(['status' => 'inactive']);

        $this->exporter()->export();
        $rows = collect($this->activeRows());

        $this->assertSame(['DM001'], $rows->pluck('employee_id')->unique()->values()->all());
        // Explicit allowlist with only inactive pins => no pins allowed, not "all pins".
        $this->assertCount(1, $rows);
        $this->assertSame('pin', $rows[0]['pin_scope']);
        $this->assertNull($rows[0]['lat']);
    }

    public function test_exported_file_is_importable(): void
    {
        $this->exporter()->export();

        $result = app(OutsourceMasterDataImportService::class)->import(
            Storage::disk('local')->path(OutsourceStoresJsonExporter::ACTIVE_PATH),
            dryRun: true,
        );

        $this->assertSame(0, $result['invalid_rows']);
        $this->assertSame(3, $result['valid_rows']);
    }

    public function test_command_dry_run_writes_nothing(): void
    {
        $exitCode = Artisan::call('outsource:export-stores', ['--dry-run' => true]);

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('DRY RUN', Artisan::output());
        $this->assertFalse(Storage::disk('local')->exists(OutsourceStoresJsonExporter::ACTIVE_PATH));
    }
}
