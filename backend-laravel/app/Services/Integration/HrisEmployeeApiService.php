<?php

namespace App\Services\Integration;

use App\Exceptions\Integration\HrisEmployeeApiException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class HrisEmployeeApiService
{
    /**
     * @param  array{page?: int, per_page?: int, search?: string, work_location?: string|null, work_area?: string|null}  $filters
     * @return array{success: bool, data: list<array<string, string|null>>, links: array, meta: array, filters: array{work_locations: list<string>, work_areas: list<string>}}
     */
    public function listEmployees(array $filters): array
    {
        $query = array_filter([
            'page' => $filters['page'] ?? 1,
            'per_page' => $filters['per_page'] ?? 25,
            'search' => $filters['search'] ?? null,
            'work_location' => $filters['work_location'] ?? null,
            'work_area' => $filters['work_area'] ?? null,
        ], static fn ($value) => $value !== null && $value !== '');

        $payload = $this->request('/api/v1/employees', $query);

        if (($payload['__status'] ?? null) === 404) {
            Log::warning('HRIS employee list endpoint returned 404.');

            throw new HrisEmployeeApiException(
                502,
                'HRIS employee list API is not available. Deploy the employee integration API to HRIS.'
            );
        }

        $data = $payload['data'] ?? null;
        $meta = $payload['meta'] ?? null;
        $links = $payload['links'] ?? null;
        $filterOptions = $payload['filters'] ?? null;

        if (
            ($payload['success'] ?? false) !== true
            || ! is_array($data)
            || ! array_is_list($data)
            || ! is_array($meta)
            || ! is_array($links)
            || ! $this->hasValidPagination($meta)
            || ! is_array($filterOptions)
            || ! $this->hasValidFilterOptions($filterOptions)
        ) {
            Log::warning('HRIS employee API returned an unexpected employee list response.', [
                'status' => $payload['__status'] ?? null,
                'keys' => array_keys($payload),
            ]);

            throw new HrisEmployeeApiException(502, 'HRIS returned an unexpected employee list response.');
        }

        $employees = [];

        foreach ($data as $employee) {
            if (! is_array($employee) || ! $this->hasValidEmployeeFields($employee)) {
                Log::warning('HRIS employee API returned an invalid employee in its list response.');

                throw new HrisEmployeeApiException(502, 'HRIS returned an unexpected employee list response.');
            }

            $employees[] = $this->allowlistedFields($employee);
        }

        return [
            'success' => true,
            'data' => $employees,
            'links' => $links,
            'meta' => $meta,
            'filters' => [
                'work_locations' => $filterOptions['work_locations'],
                'work_areas' => $filterOptions['work_areas'],
            ],
        ];
    }

    /**
     * @return array{work_locations: list<string>, work_areas: list<string>}
     */
    public function listWorkLocationOptions(): array
    {
        $response = $this->listEmployees(['page' => 1, 'per_page' => 1]);

        return $response['filters'];
    }

    /**
     * @return array<string, string|null>|null
     */
    public function findByNik(string $nik): ?array
    {
        $payload = $this->request('/api/v1/employees/by-nik/'.rawurlencode($nik));

        if (($payload['__status'] ?? null) === 404) {
            if (
                ($payload['success'] ?? null) === false
                && ($payload['message'] ?? null) === 'Employee not found.'
            ) {
                return null;
            }

            Log::warning('HRIS employee lookup endpoint returned an unexpected 404 response.');

            throw new HrisEmployeeApiException(
                502,
                'HRIS employee lookup API is not available. Deploy the employee integration API to HRIS.'
            );
        }

        $data = $payload['data'] ?? null;

        if (
            ($payload['success'] ?? false) !== true
            || ! is_array($data)
            || ! is_string($data['employee_id'] ?? null)
            || ! is_string($data['nik'] ?? null)
            || $data['nik'] !== $nik
            || ! $this->hasValidEmployeeFields($data)
        ) {
            Log::warning('HRIS employee API returned an unexpected response.', [
                'status' => $payload['__status'] ?? null,
            ]);

            throw new HrisEmployeeApiException(502, 'HRIS returned an unexpected employee response.');
        }

        return $this->allowlistedFields($data);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, string|null>
     */
    private function allowlistedFields(array $data): array
    {
        $fields = [
            'employee_id',
            'nik',
            'full_name',
            'status_employee',
            'job_position',
            'division',
            'department',
            'branch_name',
            'job_position_location',
            'area_kerja',
            'lokasi_kerja',
        ];

        return array_intersect_key($data, array_flip($fields));
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function hasValidEmployeeFields(array $data): bool
    {
        if (
            ! is_string($data['employee_id'] ?? null)
            || ! array_key_exists('nik', $data)
            || ($data['nik'] !== null && ! is_string($data['nik']))
        ) {
            return false;
        }

        foreach ([
            'full_name',
            'status_employee',
            'job_position',
            'division',
            'department',
            'branch_name',
            'job_position_location',
            'area_kerja',
            'lokasi_kerja',
        ] as $field) {
            if (array_key_exists($field, $data) && $data[$field] !== null && ! is_string($data[$field])) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param  array<string, int|string>  $query
     * @return array<string, mixed>
     */
    private function request(string $path, array $query = []): array
    {
        $baseUrl = rtrim((string) config('services.hris.base_url', ''), '/');
        $token = (string) config('services.hris.employee_api_token', '');
        $timeout = max(1, (int) config('services.hris.timeout', 8));
        $scheme = parse_url($baseUrl, PHP_URL_SCHEME);

        if (
            $baseUrl === ''
            || $token === ''
            || (! app()->environment(['local', 'testing']) && $scheme !== 'https')
        ) {
            Log::error('HRIS employee API is not configured for secure access.');

            throw new HrisEmployeeApiException(502, 'HRIS employee service is not configured.');
        }

        try {
            $response = Http::acceptJson()
                ->withToken($token)
                ->connectTimeout(min(3, $timeout))
                ->timeout($timeout)
                ->get($baseUrl.$path, $query);
        } catch (ConnectionException) {
            Log::warning('Connection to HRIS employee API failed.');

            throw new HrisEmployeeApiException(502, 'HRIS employee service is unavailable.');
        }

        if (! $response->successful() && $response->status() !== 404) {
            Log::warning('HRIS employee API request failed.', [
                'status' => $response->status(),
                'path' => $path,
            ]);

            throw new HrisEmployeeApiException(502, 'HRIS employee service returned an error.');
        }

        $payload = $response->json();

        if (! is_array($payload)) {
            Log::warning('HRIS employee API returned a non-JSON response.', [
                'status' => $response->status(),
                'path' => $path,
            ]);

            throw new HrisEmployeeApiException(502, 'HRIS returned an unexpected response.');
        }

        return [...$payload, '__status' => $response->status()];
    }

    /**
     * @param  array<string, mixed>  $meta
     */
    private function hasValidPagination(array $meta): bool
    {
        foreach (['current_page', 'per_page', 'total', 'last_page'] as $field) {
            if (! is_int($meta[$field] ?? null)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    private function hasValidFilterOptions(array $filters): bool
    {
        foreach (['work_locations', 'work_areas'] as $field) {
            if (
                ! is_array($filters[$field] ?? null)
                || ! array_is_list($filters[$field])
            ) {
                return false;
            }

            foreach ($filters[$field] as $value) {
                if (! is_string($value) || trim($value) === '') {
                    return false;
                }
            }
        }

        return true;
    }
}
