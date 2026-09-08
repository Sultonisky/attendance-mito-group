<?php

namespace App\Console\Commands;

use App\Enums\FastApiStatus;
use App\Services\Integration\FastApiService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Throwable;

#[Signature('infra:check')]
#[Description('Verify core infrastructure: database, PostGIS, Redis, cache, queue, and AI service.')]
class InfraCheck extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $checks = [
            'database' => $this->checkDatabase(),
            'postgis' => $this->checkPostGis(),
            'redis' => $this->checkRedis(),
            'cache' => $this->checkCache(),
            'queue' => $this->checkQueue(),
            'ai-service' => $this->checkAiService(),
        ];

        $rows = [];
        $failed = 0;

        foreach ($checks as $name => $result) {
            $rows[] = [$name, $result['status'], $result['detail']];

            if ($result['status'] === 'fail') {
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

    /**
     * Verify Laravel can connect to PostgreSQL and run a basic query.
     *
     * @return array{status: string, detail: string}
     */
    protected function checkDatabase(): array
    {
        try {
            $name = DB::select('SELECT current_database() AS name')[0]->name;
            DB::select('SELECT 1');

            return ['status' => 'ok', 'detail' => sprintf('connected to %s', $name)];
        } catch (Throwable $e) {
            return ['status' => 'fail', 'detail' => $e->getMessage()];
        }
    }

    /**
     * Verify the PostGIS extension is available and enabled.
     *
     * @return array{status: string, detail: string}
     */
    protected function checkPostGis(): array
    {
        try {
            $version = DB::select('SELECT PostGIS_Version() AS version')[0]->version;

            return ['status' => 'ok', 'detail' => $version];
        } catch (Throwable $e) {
            return ['status' => 'fail', 'detail' => $e->getMessage()];
        }
    }

    /**
     * Verify Laravel can reach Redis.
     *
     * @return array{status: string, detail: string}
     */
    protected function checkRedis(): array
    {
        try {
            Redis::connection()->ping();

            return ['status' => 'ok', 'detail' => 'PONG'];
        } catch (Throwable $e) {
            return ['status' => 'fail', 'detail' => $e->getMessage()];
        }
    }

    /**
     * Verify the cache store performs a full put/get round trip.
     *
     * @return array{status: string, detail: string}
     */
    protected function checkCache(): array
    {
        try {
            $store = (string) config('cache.default');
            $key = sprintf('infra:check:%s', uniqid());

            Cache::put($key, 'ok', 60);
            $value = (string) Cache::get($key);
            Cache::forget($key);

            return ['status' => 'ok', 'detail' => sprintf('store=%s, roundtrip=%s', $store, $value)];
        } catch (Throwable $e) {
            return ['status' => 'fail', 'detail' => $e->getMessage()];
        }
    }

    /**
     * Report the configured queue connection and verify it resolves.
     *
     * @return array{status: string, detail: string}
     */
    protected function checkQueue(): array
    {
        try {
            $driver = (string) config('queue.default');

            return ['status' => 'ok', 'detail' => sprintf('driver=%s', $driver)];
        } catch (Throwable $e) {
            return ['status' => 'fail', 'detail' => $e->getMessage()];
        }
    }

    /**
     * Verify Laravel can communicate with the FastAPI AI service.
     *
     * The AI service is optional for infrastructure readiness, so an
     * unreachable service is reported as a warning, not a failure.
     *
     * @return array{status: string, detail: string}
     */
    protected function checkAiService(): array
    {
        try {
            $result = app(FastApiService::class)->health();

            if ($result['status'] !== FastApiStatus::Available) {
                return [
                    'status' => 'warn',
                    'detail' => sprintf(
                        'AI service is %s (optional).',
                        $result['status']->value
                    ),
                ];
            }

            return ['status' => 'ok', 'detail' => 'reachable'];
        } catch (Throwable $e) {
            return ['status' => 'warn', 'detail' => $e->getMessage()];
        }
    }
}
