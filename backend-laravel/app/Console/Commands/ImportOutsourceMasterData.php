<?php

namespace App\Console\Commands;

use App\Services\Import\OutsourceMasterDataImportService;
use Illuminate\Console\Command;
use RuntimeException;

class ImportOutsourceMasterData extends Command
{
    protected $signature = 'outsource:import
                            {file : Path to data/stores.json (preferred) or legacy CSV/XLSX}
                            {--dry-run : Validate and report without saving changes}
                            {--allow-partial : Skip invalid rows instead of failing the whole import}
                            {--radius=150 : Pin attendance radius in meters}
                            {--require-min=0 : Fail after import when outsource count is below this (deploy guard)}
                            {--require-min-cabangs=0 : Fail when work_locations (cabang) count is below this}
                            {--require-min-pins=0 : Fail when work_location_pins count is below this}';

    protected $description = 'Import outsource cabang/kota, people, pins, and pin allowlists from stores.json.';

    public function handle(OutsourceMasterDataImportService $service): int
    {
        $file = (string) $this->argument('file');
        $dryRun = (bool) $this->option('dry-run');
        $allowPartial = (bool) $this->option('allow-partial');
        $radius = (float) $this->option('radius');
        $requireMin = max(0, (int) $this->option('require-min'));
        $requireMinCabangs = max(0, (int) $this->option('require-min-cabangs'));
        $requireMinPins = max(0, (int) $this->option('require-min-pins'));

        // JSON SOT tolerates incomplete pin rows; legacy CSV stays strict unless flagged.
        $extension = strtolower(pathinfo($file, PATHINFO_EXTENSION));
        if ($extension === 'json' && ! $this->input->hasParameterOption('--allow-partial')) {
            $allowPartial = true;
        }

        try {
            $result = $service->import($file, $dryRun, $radius, $allowPartial);
        } catch (RuntimeException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->info('Outsource Import (cabang + pins)');
        $this->line('');
        $this->line('Source: '.basename($file));
        $this->line('Rows: '.($result['total_rows'] ?? 0));
        $this->line('Valid: '.($result['valid_rows'] ?? 0));
        $this->line('Invalid: '.($result['invalid_rows'] ?? 0));
        $this->line('Pin rows: '.($result['pin_rows'] ?? 0));
        $this->line('Master-only rows: '.($result['master_only_rows'] ?? 0));
        $this->line('');

        $this->table(['Entity', 'Created', 'Existing'], [
            ['Cities', $result['cities']['created'] ?? 0, $result['cities']['existing'] ?? 0],
            ['Cabangs', $result['cabangs']['created'] ?? 0, $result['cabangs']['existing'] ?? 0],
            ['Outsources', $result['outsources']['created'] ?? 0, $result['outsources']['existing'] ?? 0],
            ['Assignments', $result['assignments']['created'] ?? 0, $result['assignments']['existing'] ?? 0],
        ]);

        $this->line('Pins created: '.($result['pins_created'] ?? 0));
        $this->line('Pins updated: '.($result['pins_updated'] ?? 0));
        $this->line('Pins already current: '.($result['pins_existing'] ?? 0));
        $this->line('Assignment pin syncs: '.($result['assignment_pins_synced'] ?? 0));

        if (! empty($result['details'])) {
            $this->table(['Row', 'Reason'], array_map(
                static fn (array $detail): array => [$detail['row'], $detail['reason']],
                array_slice($result['details'], 0, 30)
            ));
        }

        if ($dryRun) {
            $this->warn('DRY RUN — NO DATABASE CHANGES');
            $this->info('Status: SUCCESS (dry run)');

            return self::SUCCESS;
        }

        $dbCities = \App\Models\City::query()->count();
        $dbCabangs = \App\Models\WorkLocation::query()->count();
        $dbPins = \App\Models\WorkLocationPin::query()->count();
        $dbOutsources = \App\Models\Outsource::query()->count();

        $this->line('');
        $this->info('Database counts after import:');
        $this->table(['Table', 'Count'], [
            ['cities', $dbCities],
            ['work_locations (cabang)', $dbCabangs],
            ['work_location_pins', $dbPins],
            ['outsources', $dbOutsources],
        ]);

        if ($requireMin > 0 && $dbOutsources < $requireMin) {
            $this->error("Import guard failed: expected at least {$requireMin} outsource row(s), found {$dbOutsources}.");

            return self::FAILURE;
        }

        if ($requireMinCabangs > 0 && $dbCabangs < $requireMinCabangs) {
            $this->error("Import guard failed: expected at least {$requireMinCabangs} cabang/work_location row(s), found {$dbCabangs}.");

            return self::FAILURE;
        }

        if ($requireMinPins > 0 && $dbPins < $requireMinPins) {
            $this->error("Import guard failed: expected at least {$requireMinPins} pin row(s), found {$dbPins}.");

            return self::FAILURE;
        }

        $this->info('Status: SUCCESS');

        return self::SUCCESS;
    }
}
