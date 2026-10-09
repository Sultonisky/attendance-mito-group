<?php

namespace App\Console\Commands;

use App\Exceptions\Integration\HrisOutsourceDirectoryApiException;
use App\Models\Outsource;
use App\Services\Integration\HrisOutsourceDirectoryApiService;
use Illuminate\Console\Command;

class SyncOutsourcePersonsToHris extends Command
{
    protected $signature = 'hris:sync-outsource-persons
        {--execute : Create missing IDs in HRIS; without this flag the command only previews}';

    protected $description = 'Preview or sync Attendance outsource person IDs missing from HRIS';

    public function handle(HrisOutsourceDirectoryApiService $hrisDirectory): int
    {
        $dryRun = ! $this->option('execute');
        $totals = ['processed' => 0, 'created' => 0, 'skipped' => 0, 'conflict' => 0, 'would_create' => 0];

        try {
            Outsource::query()
                ->orderBy('id')
                ->chunkById(100, function ($outsources) use ($hrisDirectory, $dryRun, &$totals): void {
                    $people = $outsources
                        ->map(fn (Outsource $outsource): array => [
                            'outsource_id' => $outsource->outsource_code,
                            'full_name' => $outsource->name,
                        ])
                        ->values()
                        ->all();
                    $result = $hrisDirectory->syncPeople($people, $dryRun);

                    foreach ($totals as $key => $value) {
                        $totals[$key] += $result['meta'][$key];
                    }
                    foreach ($result['data'] as $record) {
                        if ($record['status'] !== 'skipped') {
                            $this->line(strtoupper($record['status']).': '.$record['outsource_id']);
                        }
                    }
                });
        } catch (HrisOutsourceDirectoryApiException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->table(
            ['Processed', 'Would create', 'Created', 'Skipped', 'Conflict'],
            [[
                $totals['processed'],
                $totals['would_create'],
                $totals['created'],
                $totals['skipped'],
                $totals['conflict'],
            ]],
        );

        if ($dryRun) {
            $this->comment('Preview only; no HRIS records were written. Add --execute to create missing IDs.');
        } else {
            $this->info('HRIS outsource directory sync completed.');
        }

        return self::SUCCESS;
    }
}
