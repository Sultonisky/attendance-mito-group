<?php

namespace App\Console\Commands;

use App\Services\Import\OutsourceLocationImportService;
use Illuminate\Console\Command;
use RuntimeException;

class ImportOutsourceLocations extends Command
{
    protected $signature = 'outsource:locations:import {file : Path to the generated stores.json file} {--dry-run : Validate and report without saving changes} {--allow-partial : Import valid matched rows while skipping fallback and unmatched rows} {--radius=150 : Attendance radius in meters}';

    protected $description = 'Import verified outsource store coordinates into existing work locations.';

    public function handle(OutsourceLocationImportService $service): int
    {
        try {
            $result = $service->import(
                (string) $this->argument('file'),
                (bool) $this->option('dry-run'),
                (float) $this->option('radius'),
                (bool) $this->option('allow-partial'),
            );
        } catch (RuntimeException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->info('Outsource Location Import');
        $this->line('Rows: '.($result['total_rows'] ?? 0));
        $this->line('Matched: '.($result['matched_rows'] ?? 0));
        $this->line('Updated: '.($result['updated_rows'] ?? 0));
        $this->line('Already current: '.($result['existing_rows'] ?? 0));
        $this->line('Fallback rejected: '.($result['fallback_rows'] ?? 0));
        $this->line('Invalid: '.($result['invalid_rows'] ?? 0));
        $this->line('Unmatched: '.($result['unmatched_rows'] ?? 0));
        $this->line('Partial mode: '.(! empty($result['allow_partial']) ? 'yes' : 'no'));

        if (! empty($result['details'])) {
            $this->table(['Row', 'Reason'], array_map(
                static fn (array $detail): array => [$detail['row'], $detail['reason']],
                $result['details']
            ));
        }

        if ((bool) $this->option('dry-run')) {
            $this->warn('DRY RUN — NO DATABASE CHANGES');
            return self::SUCCESS;
        }

        $this->info('Status: SUCCESS');
        return self::SUCCESS;
    }
}