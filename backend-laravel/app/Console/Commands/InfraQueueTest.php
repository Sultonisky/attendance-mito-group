<?php

namespace App\Console\Commands;

use App\Jobs\InfrastructurePing;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;

#[Signature('infra:queue-test')]
#[Description('Dispatch a smoke-test job and process it through the configured queue connection.')]
class InfraQueueTest extends Command
{
    /**
     * Maximum seconds to wait for the worker to process the job.
     */
    protected const TIMEOUT_SECONDS = 30;

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $connection = (string) config('queue.default');

        if ($connection === 'sync') {
            $this->warn('Queue connection is "sync". Set QUEUE_CONNECTION=redis to verify the Redis queue.');

            return self::FAILURE;
        }

        $marker = sprintf('infra:queue:%s', uniqid());

        $this->info("Dispatching smoke-test job on [{$connection}] connection...");

        Queue::connection($connection)->push(new InfrastructurePing($marker));

        if ($connection === 'redis') {
            $this->call('queue:work', [
                'connection' => $connection,
                '--stop-when-empty' => true,
                '--max-jobs' => 1,
            ]);
        }

        $completed = $this->waitForCompletion($marker);

        if (! $completed) {
            $this->error('The smoke-test job did not complete in time.');

            return self::FAILURE;
        }

        $this->info('Queue smoke test passed: job dispatched, processed by the worker, and completed.');

        return self::SUCCESS;
    }

    /**
     * Wait until the job reports completion through the cache marker.
     */
    private function waitForCompletion(string $marker): bool
    {
        $start = microtime(true);

        while ((microtime(true) - $start) < self::TIMEOUT_SECONDS) {
            if (Cache::get($marker) === 'done') {
                Cache::forget($marker);

                return true;
            }

            usleep(250_000);
        }

        return false;
    }
}
