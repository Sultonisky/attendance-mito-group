<?php

namespace App\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;

/**
 * Minimal infrastructure smoke-test job.
 *
 * It exists only to prove that the Redis queue connection accepts jobs and a
 * worker processes them successfully. It carries no business logic and will
 * be superseded by real domain jobs in later phases.
 */
class InfrastructurePing implements ShouldQueue
{
    use Queueable;

    /**
     * Create a new job instance.
     */
    public function __construct(
        public readonly string $marker
    ) {}

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        Cache::put($this->marker, 'done', now()->addMinutes(5));
    }
}
