<?php

namespace Tests\Feature;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class HealthTest extends TestCase
{
    /**
     * The Laravel API health endpoint returns a JSON ok payload.
     */
    public function test_api_health_endpoint_returns_ok(): void
    {
        $response = $this->get('/api/v1/health');

        $response->assertOk();
        $response->assertJsonPath('status', 'ok');
        $response->assertJsonPath('service', 'laravel-api');
    }

    /**
     * The framework health check endpoint is available.
     */
    public function test_up_endpoint_returns_ok(): void
    {
        $response = $this->get('/up');

        $response->assertOk();
    }

    /**
     * The Laravel -> FastAPI health endpoint reports both services when the
     * AI service responds.
     */
    public function test_ai_health_endpoint_returns_ai_status_when_available(): void
    {
        Http::fake(function () {
            return Http::response([
                'status' => 'ok',
                'service' => 'attendance-ai',
            ]);
        });

        $response = $this->get('/api/v1/ai-health');

        $response->assertOk();
        $response->assertJsonPath('laravel', 'ok');
        $response->assertJsonPath('ai.status', 'ok');
        $response->assertJsonPath('ai.service', 'attendance-ai');
    }

    /**
     * The Laravel -> FastAPI health endpoint returns a structured 503 instead
     * of leaking an exception when the AI service is unreachable.
     */
    public function test_ai_health_endpoint_returns_503_when_ai_unreachable(): void
    {
        Http::fake(function () {
            return Http::failedConnection();
        });

        $response = $this->get('/api/v1/ai-health');

        $response->assertStatus(503);
        $response->assertJsonPath('laravel', 'ok');
        $response->assertJsonPath('ai_status', 'unavailable');
    }

    /**
     * A timeout against the AI service is reported explicitly as a timeout,
     * never as a successful verification result.
     */
    public function test_ai_health_endpoint_reports_timeout(): void
    {
        Http::fake(function () {
            throw new ConnectionException('cURL error 28: Connection timed out');
        });

        $response = $this->get('/api/v1/ai-health');

        $response->assertStatus(503);
        $response->assertJsonPath('ai_status', 'timeout');
    }

    /**
     * A non-2xx AI service response is reported as an invalid response
     * instead of being treated as available.
     */
    public function test_ai_health_endpoint_reports_invalid_response_on_http_error(): void
    {
        Http::fake(function () {
            return Http::response(['error' => 'boom'], 500);
        });

        $response = $this->get('/api/v1/ai-health');

        $response->assertStatus(503);
        $response->assertJsonPath('ai_status', 'invalid_response');
    }
}
