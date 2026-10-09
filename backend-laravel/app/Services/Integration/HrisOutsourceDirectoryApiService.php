<?php

namespace App\Services\Integration;

use App\Exceptions\Integration\HrisOutsourceDirectoryApiException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class HrisOutsourceDirectoryApiService
{
    /**
     * @param  list<array{outsource_id: string, full_name: string}>  $people
     * @return array{
     *   data: list<array{outsource_id: string, status: 'created'|'skipped'|'conflict'|'would_create'}>,
     *   meta: array{processed: int, created: int, skipped: int, conflict: int, would_create: int}
     * }
     */
    public function syncPeople(array $people, bool $dryRun = false): array
    {
        $baseUrl = rtrim((string) config('services.hris.base_url', ''), '/');
        $token = (string) config('services.hris.outsource_sync_api_token', '');
        $timeout = max(1, (int) config('services.hris.timeout', 8));
        $path = '/api/v1/outsource/persons/sync';

        if (
            $baseUrl === ''
            || $token === ''
            || (! app()->environment(['local', 'testing']) && parse_url($baseUrl, PHP_URL_SCHEME) !== 'https')
        ) {
            Log::error('HRIS outsource directory sync API is not configured for secure access.');

            throw new HrisOutsourceDirectoryApiException('HRIS directory sync is not configured securely.');
        }

        try {
            $response = Http::acceptJson()
                ->withToken($token)
                ->connectTimeout(min(3, $timeout))
                ->timeout($timeout)
                ->post($baseUrl.$path, [
                    'people' => $people,
                    'dry_run' => $dryRun,
                ]);
        } catch (ConnectionException) {
            Log::warning('Connection to HRIS outsource directory sync API failed.', ['path' => $path]);

            throw new HrisOutsourceDirectoryApiException('HRIS directory sync service is unavailable.');
        }

        if (! $response->successful()) {
            Log::warning('HRIS outsource directory sync API request failed.', [
                'status' => $response->status(),
                'path' => $path,
            ]);

            throw new HrisOutsourceDirectoryApiException('HRIS directory sync service returned an error.');
        }

        $payload = $response->json();
        if (
            ! is_array($payload)
            || ($payload['success'] ?? false) !== true
            || ! is_array($payload['data'] ?? null)
            || ! array_is_list($payload['data'])
            || count($payload['data']) !== count($people)
        ) {
            Log::warning('HRIS outsource directory sync API returned an unexpected response.', ['path' => $path]);

            throw new HrisOutsourceDirectoryApiException('HRIS returned an unexpected directory sync response.');
        }

        $statuses = ['created', 'skipped', 'conflict', 'would_create'];
        $results = [];
        foreach ($people as $index => $person) {
            $record = $payload['data'][$index];
            if (
                ! is_array($record)
                || ! is_string($record['outsource_id'] ?? null)
                || strcasecmp($record['outsource_id'], $person['outsource_id']) !== 0
                || ! in_array($record['status'] ?? null, $statuses, true)
            ) {
                Log::warning('HRIS outsource directory sync API returned an invalid record.', ['path' => $path]);

                throw new HrisOutsourceDirectoryApiException('HRIS returned an unexpected directory sync response.');
            }

            $results[] = [
                'outsource_id' => $record['outsource_id'],
                'status' => $record['status'],
            ];
        }

        return [
            'data' => $results,
            'meta' => [
                'processed' => count($results),
                'created' => count(array_filter($results, static fn (array $row): bool => $row['status'] === 'created')),
                'skipped' => count(array_filter($results, static fn (array $row): bool => $row['status'] === 'skipped')),
                'conflict' => count(array_filter($results, static fn (array $row): bool => $row['status'] === 'conflict')),
                'would_create' => count(array_filter($results, static fn (array $row): bool => $row['status'] === 'would_create')),
            ],
        ];
    }
}
