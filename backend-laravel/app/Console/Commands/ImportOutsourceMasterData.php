<?php

namespace App\Console\Commands;

use App\Services\Import\OutsourceMasterDataImportService;
use Illuminate\Console\Command;
use RuntimeException;

class ImportOutsourceMasterData extends Command
{
    protected $signature = 'outsource:import {file : Path to the Excel/CSV file} {--dry-run : Validate and report what would be imported without saving changes}';

    protected $description = 'Import outsource master data from Excel/CSV into City, WorkLocation, Outsource, and assignment tables.';

    public function handle(OutsourceMasterDataImportService $service): int
    {
        $file = $this->argument('file');
        $dryRun = (bool) $this->option('dry-run');

        try {
            $result = $service->import($file, $dryRun);
        } catch (RuntimeException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->info('Outsource Master Data Import');
        $this->line('');
        $this->line('Source: '.basename($file));
        $this->line('Rows: '.($result['total_rows'] ?? 0));
        $this->line('Valid rows: '.($result['valid_rows'] ?? 0));
        $this->line('Invalid rows: '.($result['invalid_rows'] ?? 0));
        $this->line('');

        $this->table([
            'Entity',
            'Created',
            'Existing',
        ], [
            ['Cities', $result['cities']['created'] ?? 0, $result['cities']['existing'] ?? 0],
            ['Stores', $result['stores']['created'] ?? 0, $result['stores']['existing'] ?? 0],
            ['Outsources', $result['outsources']['created'] ?? 0, $result['outsources']['existing'] ?? 0],
            ['Assignments', $result['assignments']['created'] ?? 0, $result['assignments']['existing'] ?? 0],
        ]);

        if (($result['invalid_rows'] ?? 0) > 0) {
            $this->warn('Invalid rows detected:');
            foreach ($result['invalid_details'] ?? [] as $detail) {
                $this->line(sprintf('Row %s: %s is empty', $detail['row_number'], $detail['field']));
            }
        }

        if ($dryRun) {
            $this->warn('DRY RUN — NO DATABASE CHANGES');
            $this->info('Status: SUCCESS (dry run)');

            return self::SUCCESS;
        }

        $this->info('Status: SUCCESS');

        return self::SUCCESS;
    }
}
