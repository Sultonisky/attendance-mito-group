<?php

namespace App\Services\System;

use App\Enums\FastApiStatus;
use App\Services\Integration\FastApiService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Throwable;

/**
 * Shared infrastructure probes used by `infra:check` and the Systems API.
 *
 * Returns facts only — callers decide how to present or act on them.
 */
class InfrastructureHealthService
{
    /**
     * @return array{
     *   overall_status: string,
     *   refreshed_at: string,
     *   services: list<array{key: string, label: string, status: string, detail: string}>,
     *   runtime: array{
     *     environment: string,
     *     debug: bool,
     *     laravel_version: string,
     *     php_version: string,
     *     timezone: string,
     *     maintenance: bool
     *   }
     * }
     */
    public function snapshot(): array
    {
        $checks = [
            'laravel_api' => [
                'label' => 'Attendance API',
                'result' => ['status' => 'ok', 'detail' => 'reachable'],
            ],
            'database' => [
                'label' => 'PostgreSQL',
                'result' => $this->checkDatabase(),
            ],
            'postgis' => [
                'label' => 'PostGIS',
                'result' => $this->checkPostGis(),
            ],
            'redis' => [
                'label' => 'Redis',
                'result' => $this->checkRedis(),
            ],
            'cache' => [
                'label' => 'Cache',
                'result' => $this->checkCache(),
            ],
            'queue' => [
                'label' => 'Queue',
                'result' => $this->checkQueue(),
            ],
            'ai_service' => [
                'label' => 'AI Face Service',
                'result' => $this->checkAiService(),
            ],
        ];

        $services = [];
        $failed = 0;
        $warned = 0;

        foreach ($checks as $key => $check) {
            $status = $check['result']['status'];
            $services[] = [
                'key' => $key,
                'label' => $check['label'],
                'status' => $status,
                'detail' => $check['result']['detail'],
            ];

            if ($status === 'fail') {
                $failed++;
            } elseif ($status === 'warn') {
                $warned++;
            }
        }

        $overall = 'ok';
        if ($failed > 0) {
            $overall = 'fail';
        } elseif ($warned > 0) {
            $overall = 'degraded';
        }

        return [
            'overall_status' => $overall,
            'refreshed_at' => now()->toIso8601String(),
            'services' => $services,
            'runtime' => [
                'environment' => (string) config('app.env'),
                'debug' => (bool) config('app.debug'),
                'laravel_version' => app()->version(),
                'php_version' => PHP_VERSION,
                'timezone' => (string) config('app.timezone'),
                'maintenance' => app()->isDownForMaintenance(),
            ],
        ];
    }

    /**
     * @return array{status: string, detail: string}
     */
    public function checkDatabase(): array
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
     * @return array{status: string, detail: string}
     */
    public function checkPostGis(): array
    {
        try {
            $version = DB::select('SELECT PostGIS_Version() AS version')[0]->version;

            return ['status' => 'ok', 'detail' => $version];
        } catch (Throwable $e) {
            return ['status' => 'fail', 'detail' => $e->getMessage()];
        }
    }

    /**
     * @return array{status: string, detail: string}
     */
    public function checkRedis(): array
    {
        try {
            Redis::connection()->ping();

            return ['status' => 'ok', 'detail' => 'PONG'];
        } catch (Throwable $e) {
            return ['status' => 'fail', 'detail' => $e->getMessage()];
        }
    }

    /**
     * @return array{status: string, detail: string}
     */
    public function checkCache(): array
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
     * @return array{status: string, detail: string}
     */
    public function checkQueue(): array
    {
        try {
            $driver = (string) config('queue.default');

            return ['status' => 'ok', 'detail' => sprintf('driver=%s', $driver)];
        } catch (Throwable $e) {
            return ['status' => 'fail', 'detail' => $e->getMessage()];
        }
    }

    /**
     * AI is optional for infrastructure readiness — unreachable → warn, not fail.
     *
     * @return array{status: string, detail: string}
     */
    public function checkAiService(): array
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
