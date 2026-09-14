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
     * Resolve the API key used to authenticate FastAPI requests.
     */
    public function apiKey(): string
    {
        return (string) config('services.fastapi.api_key', '');
    }

    /**
     * Build a base HTTP client request with auth and timeout.
     */
    private function client()
    {
        return Http::timeout($this->timeout())
            ->acceptJson()
            ->withHeaders([
                'X-API-Key' => $this->apiKey(),
            ]);
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
            $response = $this->client()->get($this->baseUrl().'/health');
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
     * Enroll a face image for an employee.
     *
     * @param  string  $employeeId  Employee identifier (employee_code).
     * @param  string  $imagePath  Absolute path to the face image file.
     * @param  string|null  $mimeType  Optional MIME type; inferred from path if omitted.
     * @return array{status: FastApiStatus, data: array{enrolled: bool, model_version: string, embedding_reference: string, face_detected: bool, quality_score: float}|null, error: string|null}
     */
    public function enroll(string $employeeId, string $imagePath, ?string $mimeType = null): array
    {
        return $this->callEndpoint('/face/enroll', [
            'employee_id' => $employeeId,
        ], $imagePath, $mimeType);
    }

    /**
     * Verify a probe face image against the enrolled embedding for an employee.
     *
     * @param  string  $employeeId  Employee identifier (employee_code).
     * @param  string  $imagePath  Absolute path to the probe face image file.
     * @param  string|null  $embeddingReference  Optional opaque reference from enrollment.
     * @param  string|null  $mimeType  Optional MIME type; inferred from path if omitted.
     * @return array{status: FastApiStatus, data: array{verified: bool, confidence: float, liveness: bool, liveness_reason: string|null, face_detected: bool, model_version: string, processing_time_ms: int, quality_score: float}|null, error: string|null}
     */
    public function verify(string $employeeId, string $imagePath, ?string $embeddingReference = null, ?string $mimeType = null): array
    {
        $formFields = [
            'employee_id' => $employeeId,
        ];

        if ($embeddingReference !== null) {
            $formFields['embedding_reference'] = $embeddingReference;
        }

        return $this->callEndpoint('/face/verify', $formFields, $imagePath, $mimeType);
    }

    /**
     * Common endpoint caller for enroll/verify.
     *
     * @param  string  $endpoint  /face/enroll or /face/verify
     * @param  array<string, string>  $formFields
     * @return array{status: FastApiStatus, data: array<string, mixed>|null, error: string|null}
     */
    private function callEndpoint(string $endpoint, array $formFields, string $imagePath, ?string $mimeType = null): array
    {
        $mimeType = $mimeType ?? $this->inferMimeType($imagePath);
        $filename = basename($imagePath);

        try {
            $response = $this->client()
                ->attach('image', file_get_contents($imagePath), $filename, [
                    'Content-Type' => $mimeType,
                ])
                ->asMultipart()
                ->post($this->baseUrl().$endpoint, $formFields);
        } catch (ConnectionException $e) {
            return [
                'status' => $this->resolveConnectionFailure($e),
                'data' => null,
                'error' => $e->getMessage(),
            ];
        } catch (Throwable $e) {
            return [
                'status' => FastApiStatus::Unavailable,
                'data' => null,
                'error' => $e->getMessage(),
            ];
        }

        $payload = $response->json();

        if (! $response->successful() || ! is_array($payload)) {
            return [
                'status' => FastApiStatus::InvalidResponse,
                'data' => null,
                'error' => is_array($payload) && isset($payload['detail'])
                    ? (string) $payload['detail']
                    : 'Unexpected response from AI service',
            ];
        }

        return [
            'status' => FastApiStatus::Available,
            'data' => $payload,
            'error' => null,
        ];
    }

    /**
     * Infer MIME type from file extension.
     */
    private function inferMimeType(string $path): string
    {
        $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));

        return match ($ext) {
            'jpg', 'jpeg' => 'image/jpeg',
            'png' => 'image/png',
            'webp' => 'image/webp',
            default => 'application/octet-stream',
        };
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
