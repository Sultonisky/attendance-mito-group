<?php

namespace Tests\Unit;

use App\Jobs\InfrastructurePing;
use Illuminate\Support\Facades\Cache;
use Mockery;
use Tests\TestCase;

class InfrastructurePingTest extends TestCase
{
    /**
     * The smoke-test job writes its completion marker and nothing else.
     */
    public function test_job_marks_completion_marker(): void
    {
        $store = [];

        Cache::clearResolvedInstances();

        $job = new InfrastructurePing('infra:queue:test-marker');

        Cache::shouldReceive('put')
            ->once()
            ->with(Mockery::any(), Mockery::any(), Mockery::any())
            ->andReturnUsing(function (string $key, string $value, $ttl) use (&$store) {
                $store[$key] = $value;

                return true;
            });

        $job->handle();

        $this->assertSame('done', $store['infra:queue:test-marker'] ?? null);
    }
}
