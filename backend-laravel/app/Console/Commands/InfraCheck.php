<?php

namespace App\Console\Commands;

use App\Services\System\InfrastructureHealthService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('infra:check')]
#[Description('Verify core infrastructure: database, PostGIS, Redis, cache, queue, and AI service.')]
class InfraCheck extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(InfrastructureHealthService $health): int
    {
        $snapshot = $health->snapshot();

        $rows = [];
        $failed = 0;

        foreach ($snapshot['services'] as $service) {
            // Skip the synthetic "laravel_api" row — the CLI already runs inside Laravel.
            if ($service['key'] === 'laravel_api') {
                continue;
            }

            $rows[] = [$service['key'], $service['status'], $service['detail']];

            if ($service['status'] === 'fail') {
                $failed++;
            }
        }

        $this->table(['Component', 'Status', 'Detail'], $rows);

        if ($failed > 0) {
            $this->error(sprintf('%d infrastructure component(s) failed.', $failed));

            return self::FAILURE;
        }

        $this->info('All infrastructure components are healthy.');

        return self::SUCCESS;
    }
}
