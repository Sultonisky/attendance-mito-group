<?php

namespace App\Services\Integration;

use App\Enums\FastApiStatus;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Thin client for the internal FastAPI (AI/CV) service.
 *
 * FastAPI only provides facts (face verification, liveness, model inference,
 * etc.). Laravel remains the business authority and decides what those facts
 * mean for the business process.
 *
 * This service currently supports infrastructure health checks. It will be
 * extended for AI orchestration in later phases.
 */
class FastApiService
{
    /**
     * Resolve the base URL of the FastAPI service.
     */
    public function baseUrl(): string
    {
        return rtrim((string) config('services.fastapi.base_url', 'http://127.0.0.1:8001'), '/');
    }

    /**
     * Resolve the request timeout (seconds).
     */
    public function timeout(): int
    {
        return (int) config('services.fastapi.timeout', 5);
    }

    /**
     * Ping the FastAPI health endpoint.
     *
     * Failures are represented explicitly through FastApiStatus and are never
     * silently mapped to a successful result.
     *
     * @return array{status: FastApiStatus, health: array<string, mixed>|null}
     */
    public function health(): array
    {
        try {
            $response = Http::timeout($this->timeout())
                ->acceptJson()
                ->get($this->baseUrl().'/health');
        } catch (ConnectionException $e) {
            return [
                'status' => $this->resolveConnectionFailure($e),
                'health' => null,
            ];
        } catch (Throwable) {
            return [
                'status' => FastApiStatus::Unavailable,
                'health' => null,
            ];
        }

        if (! $response->successful()) {
            return [
                'status' => FastApiStatus::InvalidResponse,
                'health' => null,
            ];
        }

        $payload = $response->json();

        if (! is_array($payload)) {
            return [
                'status' => FastApiStatus::InvalidResponse,
                'health' => null,
            ];
        }

        return [
            'status' => FastApiStatus::Available,
            'health' => $payload,
        ];
    }

    /**
     * Distinguish a slow/timeout failure from an unreachable service.
     */
    private function resolveConnectionFailure(ConnectionException $exception): FastApiStatus
    {
        $message = strtolower($exception->getMessage());

        if (str_contains($message, 'timed out') || str_contains($message, 'timeout')) {
            return FastApiStatus::Timeout;
        }

        return FastApiStatus::Unavailable;
    }
}
