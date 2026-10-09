<?php

namespace App\Services\Integration;

use App\Exceptions\Integration\HrisOutsourcePayrollApiException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class HrisOutsourcePayrollApiService
{
    /**
     * @return list<array{period: string, outsource_id: string, full_name: string|null, vendor: string|null, hke: int|float, basic_salary: int|float, bpjs_kesehatan_deduction: int|float, loan_deduction: int|float, take_home_pay: int|float}>
     */
    public function payslips(string $outsourceId, ?string $period = null): array
    {
        return $this->fetch($outsourceId, 'payslips', $period, [
            'hke',
            'basic_salary',
            'bpjs_kesehatan_deduction',
            'loan_deduction',
            'take_home_pay',
        ]);
    }

    /**
     * @return list<array{period: string, outsource_id: string, full_name: string|null, vendor: string|null, umk_amount: int|float, incentive_amount: int|float}>
     */
    public function incentives(string $outsourceId, ?string $period = null): array
    {
        return $this->fetch($outsourceId, 'incentives', $period, [
            'umk_amount',
            'incentive_amount',
        ]);
    }

    /**
     * @param  list<string>  $amountFields
     * @return list<array<string, string|int|float|null>>
     */
    private function fetch(string $outsourceId, string $resource, ?string $period, array $amountFields): array
    {
        $baseUrl = rtrim((string) config('services.hris.base_url', ''), '/');
        $token = (string) config('services.hris.outsource_payroll_api_token', '');
        $timeout = max(1, (int) config('services.hris.timeout', 8));
        $scheme = parse_url($baseUrl, PHP_URL_SCHEME);

        if (
            $baseUrl === ''
            || $token === ''
            || (! app()->environment(['local', 'testing']) && $scheme !== 'https')
        ) {
            Log::error('HRIS outsource payroll API is not configured for secure access.');

            throw new HrisOutsourcePayrollApiException(502, 'HRIS payroll service is not configured.');
        }

        $path = '/api/v1/outsource/'.rawurlencode($outsourceId).'/'.$resource;

        try {
            $response = Http::acceptJson()
                ->withToken($token)
                ->connectTimeout(min(3, $timeout))
                ->timeout($timeout)
                ->get($baseUrl.$path, array_filter(
                    ['period' => $period],
                    static fn ($value): bool => $value !== null && $value !== '',
                ));
        } catch (ConnectionException) {
            Log::warning('Connection to HRIS outsource payroll API failed.', ['path' => $path]);

            throw new HrisOutsourcePayrollApiException(502, 'HRIS payroll service is unavailable.');
        }

        if (! $response->successful()) {
            Log::warning('HRIS outsource payroll API request failed.', [
                'status' => $response->status(),
                'path' => $path,
            ]);

            throw new HrisOutsourcePayrollApiException(502, 'HRIS payroll service returned an error.');
        }

        $payload = $response->json();
        if (
            ! is_array($payload)
            || ($payload['success'] ?? false) !== true
            || ! is_string($payload['outsource_id'] ?? null)
            || strcasecmp($payload['outsource_id'], $outsourceId) !== 0
            || ! is_array($payload['data'] ?? null)
            || ! array_is_list($payload['data'])
        ) {
            Log::warning('HRIS outsource payroll API returned an unexpected response.', ['path' => $path]);

            throw new HrisOutsourcePayrollApiException(502, 'HRIS returned an unexpected payroll response.');
        }

        $records = [];
        foreach ($payload['data'] as $record) {
            if (! is_array($record) || ! $this->hasValidRecord($record, $outsourceId, $amountFields)) {
                Log::warning('HRIS outsource payroll API returned an invalid record.', ['path' => $path]);

                throw new HrisOutsourcePayrollApiException(502, 'HRIS returned an unexpected payroll response.');
            }

            $allowed = [
                'period',
                'outsource_id',
                'full_name',
                'vendor',
                ...$amountFields,
            ];
            $records[] = array_intersect_key($record, array_flip($allowed));
        }

        return $records;
    }

    /**
     * @param  array<string, mixed>  $record
     * @param  list<string>  $amountFields
     */
    private function hasValidRecord(array $record, string $outsourceId, array $amountFields): bool
    {
        if (
            ! is_string($record['period'] ?? null)
            || ! preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $record['period'])
            || ! is_string($record['outsource_id'] ?? null)
            || strcasecmp($record['outsource_id'], $outsourceId) !== 0
        ) {
            return false;
        }

        foreach (['full_name', 'vendor'] as $field) {
            if (array_key_exists($field, $record) && $record[$field] !== null && ! is_string($record[$field])) {
                return false;
            }
        }

        foreach ($amountFields as $field) {
            if (
                ! array_key_exists($field, $record)
                || (! is_int($record[$field]) && ! (is_float($record[$field]) && is_finite($record[$field])))
            ) {
                return false;
            }
        }

        return true;
    }
}
