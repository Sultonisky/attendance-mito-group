<?php

namespace App\Console\Commands;

use App\Services\Export\OutsourceStoresJsonExporter;
use Illuminate\Console\Command;

/**
 * Manual DB → stores.json snapshot. Read-only against the database; the
 * previous file is rotated into backups/ so every run keeps a version.
 */
class ExportOutsourceStoresJson extends Command
{
    protected $signature = 'outsource:export-stores
                            {--dry-run : Report row counts without writing files}';

    protected $description = 'Write current outsource persons/cabang/pins from the DB to storage stores.json (previous file kept as backup)';

    public function handle(OutsourceStoresJsonExporter $exporter): int
    {
        $result = $exporter->export((bool) $this->option('dry-run'));

        $this->line("Persons: {$result['persons']}");
        $this->line("Rows: {$result['rows']}");

        match ($result['status']) {
            OutsourceStoresJsonExporter::STATUS_DRY_RUN => $this->warn('DRY RUN — no files written.'),
            OutsourceStoresJsonExporter::STATUS_UNCHANGED => $this->info("No changes since last export: {$result['path']}"),
            default => $this->info("Written: {$result['path']}"),
        };

        if ($result['backup'] !== null) {
            $this->line("Previous version: {$result['backup']}");
        }

        return self::SUCCESS;
    }
}
