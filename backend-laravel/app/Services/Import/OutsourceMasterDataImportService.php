<?php

namespace App\Services\Import;

use App\Models\City;
use App\Models\Outsource;
use App\Models\OutsourceStoreAssignment;
use App\Models\WorkLocation;
use Illuminate\Support\Facades\DB;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Reader\Xlsx as XlsxReader;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use RuntimeException;

class OutsourceMasterDataImportService
{
    /**
     * @var array<string, mixed>
     */
    protected array $normalizedCities = [];

    /**
     * @var array<string, mixed>
     */
    protected array $normalizedStores = [];

    /**
     * @var array<string, mixed>
     */
    protected array $normalizedOutsources = [];

    /**
     * @var array<string, mixed>
     */
    protected array $normalizedAssignments = [];

    /**
     * @param  string  $filePath
     * @param  bool  $dryRun
     * @return array<string, mixed>
     */
    public function import(string $filePath, bool $dryRun = false): array
    {
        $this->resetState();

        $absolutePath = $this->resolvePath($filePath);
        $rows = $this->readRows($absolutePath);

        $summary = [
            'total_rows' => count($rows),
            'valid_rows' => 0,
            'invalid_rows' => 0,
            'skipped_blank_rows' => 0,
            'cities' => ['created' => 0, 'existing' => 0],
            'stores' => ['created' => 0, 'existing' => 0],
            'outsources' => ['created' => 0, 'existing' => 0],
            'assignments' => ['created' => 0, 'existing' => 0],
            'dry_run' => $dryRun,
            'invalid_details' => [],
        ];

        $validRows = [];

        foreach ($rows as $rowNumber => $row) {
            // Excel used-range often pads to ~1000 empty rows; skip those.
            if ($this->isBlankRow($row)) {
                $summary['skipped_blank_rows']++;
                continue;
            }

            $normalized = $this->normalizeRow($row, is_int($rowNumber) ? $rowNumber + 2 : $rowNumber);

            if ($normalized['valid'] === false) {
                $summary['invalid_rows']++;
                $summary['invalid_details'][] = $normalized['error'];
                continue;
            }

            $validRows[] = $normalized['data'];
        }

        if ($summary['invalid_rows'] > 0) {
            $preview = array_slice($summary['invalid_details'], 0, 10);
            $lines = array_map(
                static fn (array $detail): string => sprintf(
                    'Row %s: %s is empty',
                    $detail['row_number'] ?? '?',
                    $detail['field'] ?? 'field'
                ),
                $preview
            );
            $more = $summary['invalid_rows'] > 10
                ? sprintf("\n... and %d more", $summary['invalid_rows'] - 10)
                : '';

            throw new RuntimeException(
                sprintf(
                    "Import failed: %d invalid row(s) found.\n%s%s",
                    $summary['invalid_rows'],
                    implode("\n", $lines),
                    $more
                )
            );
        }

        $summary['valid_rows'] = count($validRows);

        if ($dryRun) {
            return $this->buildDryRunSummary($validRows, $summary);
        }

        $citySeen = [];
        $storeSeen = [];
        $outsourceSeen = [];
        $assignmentSeen = [];

        DB::transaction(function () use ($validRows, &$summary, &$citySeen, &$storeSeen, &$outsourceSeen, &$assignmentSeen) {
            foreach ($validRows as $row) {
                $city = $this->resolveCity($row['city_name']);
                $store = $this->resolveStore($city, $row['store_name']);
                $outsource = $this->resolveOutsource($row['outsource_name']);
                $assignment = $this->resolveAssignment($outsource->id, $store->id);

                $cityKey = $this->makeKey('city', $city->name);
                $storeKey = $this->makeKey('store', $city->id, $store->name);
                $outsourceKey = $this->makeKey('outsource', $outsource->name);
                $assignmentKey = $this->makeKey('assignment', $outsource->id, $store->id);

                if (! isset($citySeen[$cityKey])) {
                    $citySeen[$cityKey] = $city->wasRecentlyCreated;
                }
                if (! isset($storeSeen[$storeKey])) {
                    $storeSeen[$storeKey] = $store->wasRecentlyCreated;
                }
                if (! isset($outsourceSeen[$outsourceKey])) {
                    $outsourceSeen[$outsourceKey] = $outsource->wasRecentlyCreated;
                }
                if (! isset($assignmentSeen[$assignmentKey])) {
                    $assignmentSeen[$assignmentKey] = $assignment->wasRecentlyCreated;
                }
            }

            $summary['cities']['created'] = count(array_filter($citySeen, fn($created) => $created === true));
            $summary['cities']['existing'] = count(array_filter($citySeen, fn($created) => $created === false));

            $summary['stores']['created'] = count(array_filter($storeSeen, fn($created) => $created === true));
            $summary['stores']['existing'] = count(array_filter($storeSeen, fn($created) => $created === false));

            $summary['outsources']['created'] = count(array_filter($outsourceSeen, fn($created) => $created === true));
            $summary['outsources']['existing'] = count(array_filter($outsourceSeen, fn($created) => $created === false));

            $summary['assignments']['created'] = count(array_filter($assignmentSeen, fn($created) => $created === true));
            $summary['assignments']['existing'] = count(array_filter($assignmentSeen, fn($created) => $created === false));
        });

        return $summary;
    }

    protected function resolvePath(string $filePath): string
    {
        $path = trim($filePath);

        if ($path === '') {
            throw new RuntimeException('The import file path is required.');
        }

        $absolutePath = $path;
        if (
            ! str_starts_with($absolutePath, DIRECTORY_SEPARATOR)
            && ! str_contains($absolutePath, ':\\')
            && ! str_contains($absolutePath, ':/')
        ) {
            $absolutePath = getcwd() . DIRECTORY_SEPARATOR . $absolutePath;
        }

        if (! file_exists($absolutePath)) {
            throw new RuntimeException(sprintf('The import file was not found: %s', $absolutePath));
        }

        $extension = strtolower(pathinfo($absolutePath, PATHINFO_EXTENSION));
        if (! in_array($extension, ['csv', 'xlsx'], true)) {
            throw new RuntimeException(sprintf('Unsupported spreadsheet format: %s. Only .csv and .xlsx are allowed.', $extension));
        }

        return $absolutePath;
    }

    protected function readRows(string $filePath): array
    {
        $extension = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));

        if ($extension === 'csv') {
            $rows = [];
            $handle = fopen($filePath, 'r');
            if ($handle === false) {
                throw new RuntimeException(sprintf('Unable to open file: %s', $filePath));
            }

            $header = null;
            while (($row = fgetcsv($handle)) !== false) {
                if ($row === [null] || $row === []) {
                    continue;
                }

                if ($header === null) {
                    $header = $this->canonicalizeHeaders(array_map(fn($value) => $this->normalizeHeader($value), $row));
                    continue;
                }

                $rows[] = array_combine($header, $row);
            }

            fclose($handle);

            return $rows;
        }

        $reader = new XlsxReader();
        $spreadsheet = $reader->load($filePath);
        $worksheet = $spreadsheet->getActiveSheet();
        $rows = $worksheet->toArray();

        if (count($rows) < 2) {
            throw new RuntimeException('The Excel file does not contain any data rows.');
        }

        $header = array_map(fn($value) => $this->normalizeHeader($value), $rows[0]);
        $header = $this->canonicalizeHeaders($header);
        $expectedHeaders = ['LIST CABANG', 'NAMA TOKO', 'NAMA SPG'];
        if (! empty(array_diff($expectedHeaders, $header))) {
            throw new RuntimeException('The worksheet is missing one or more required headers: LIST CABANG, NAMA TOKO, NAMA SPG (or NAMA KARYAWAN).');
        }

        $dataRows = [];
        for ($i = 1; $i < count($rows); $i++) {
            $dataRows[] = array_combine($header, $rows[$i]);
        }

        return $dataRows;
    }

    protected function normalizeHeader(?string $value): string
    {
        return trim((string) $value);
    }

    /**
     * @param  list<string>  $headers
     * @return list<string>
     */
    protected function canonicalizeHeaders(array $headers): array
    {
        return array_map(function (string $header): string {
            $upper = strtoupper(trim($header));
            if (in_array($upper, ['NAMA KARYAWAN', 'NAMA KARYA WAN', 'NAMA OUTSOURCE', 'EMPLOYEE NAME'], true)) {
                return 'NAMA SPG';
            }

            return $header;
        }, $headers);
    }

    /**
     * @param  array<string, mixed>  $row
     */
    protected function isBlankRow(array $row): bool
    {
        foreach ($row as $value) {
            if ($this->normalizeString($value) !== null) {
                return false;
            }
        }

        return true;
    }

    protected function normalizeRow(array $row, int $rowNumber): array
    {
        $city = $this->normalizeString($row['LIST CABANG'] ?? null);
        $store = $this->normalizeString($row['NAMA TOKO'] ?? null);
        $outsource = $this->normalizeString($row['NAMA SPG'] ?? $row['NAMA KARYAWAN'] ?? null);

        if ($city === null || $store === null || $outsource === null) {
            return [
                'valid' => false,
                'error' => [
                    'row_number' => $rowNumber,
                    'field' => $city === null ? 'LIST CABANG' : ($store === null ? 'NAMA TOKO' : 'NAMA SPG'),
                    'reason' => 'required value is missing',
                    'value' => $city ?? $store ?? $outsource,
                ],
            ];
        }

        return [
            'valid' => true,
            'data' => [
                'city_name' => $this->normalizeName($city),
                'store_name' => $this->normalizeName($store),
                'outsource_name' => $this->normalizeName($outsource),
            ],
        ];
    }

    protected function normalizeString(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $string = trim((string) $value);
        if ($string === '') {
            return null;
        }

        $string = preg_replace('/\s+/', ' ', $string);

        return $string !== null ? trim($string) : null;
    }

    protected function normalizeName(string $value): string
    {
        return preg_replace('/\s+/', ' ', trim($value)) ?? $value;
    }

    protected function buildDryRunSummary(array $validRows, array $summary): array
    {
        $cityKeys = [];
        $storeKeys = [];
        $outsourceKeys = [];
        $assignmentKeys = [];

        foreach ($validRows as $row) {
            $cityKey = $this->makeKey('city', $row['city_name']);
            $storeKey = $this->makeKey('store', $row['city_name'], $row['store_name']);
            $outsourceKey = $this->makeKey('outsource', $row['outsource_name']);
            $assignmentKey = $this->makeKey('assignment', $row['city_name'], $row['store_name'], $row['outsource_name']);

            $cityKeys[$cityKey] = $row['city_name'];
            $storeKeys[$storeKey] = $row['store_name'];
            $outsourceKeys[$outsourceKey] = $row['outsource_name'];
            $assignmentKeys[$assignmentKey] = true;
        }

        $summary['cities']['created'] = count($cityKeys);
        $summary['stores']['created'] = count($storeKeys);
        $summary['outsources']['created'] = count($outsourceKeys);
        $summary['assignments']['created'] = count($assignmentKeys);
        $summary['cities']['existing'] = 0;
        $summary['stores']['existing'] = 0;
        $summary['outsources']['existing'] = 0;
        $summary['assignments']['existing'] = 0;

        return $summary;
    }

    protected function summarizeCreatedExisting(string $type, array $counts): array
    {
        $counts['created'] = 0;
        $counts['existing'] = 0;

        switch ($type) {
            case 'city':
                $counts['created'] = count($this->normalizedCities);
                break;
            case 'store':
                $counts['created'] = count($this->normalizedStores);
                break;
            case 'outsource':
                $counts['created'] = count($this->normalizedOutsources);
                break;
            case 'assignment':
                $counts['created'] = count($this->normalizedAssignments);
                break;
        }

        return $counts;
    }

    protected function resolveCity(string $name): City
    {
        $city = City::withTrashed()->firstOrCreate(
            ['name' => $name],
            ['code' => $this->generateCityCode($name), 'status' => 'active'],
        );

        if ($city->trashed()) {
            $city->restore();
        }

        if (empty($city->code)) {
            $city->code = $this->generateCityCode($name);
            $city->save();
        }

        if ($city->status !== 'active') {
            $city->status = 'active';
            $city->save();
        }

        return $city->refresh();
    }

    protected function resolveStore(City $city, string $storeName): WorkLocation
    {
        $store = WorkLocation::withTrashed()->firstOrCreate(
            ['city_id' => $city->id, 'name' => $storeName],
            [
                'code' => $this->generateLocationCode($city->name, $storeName),
                'latitude' => null,
                'longitude' => null,
                'radius_meters' => null,
                'status' => 'active',
            ],
        );

        if ($store->trashed()) {
            $store->restore();
        }

        if ($store->status !== 'active') {
            $store->status = 'active';
            $store->save();
        }

        return $store->refresh();
    }

    protected function resolveOutsource(string $name): Outsource
    {
        $outsource = Outsource::withTrashed()->firstOrCreate(
            ['name' => $name],
            ['outsource_code' => Outsource::generateNextCode(), 'status' => 'active'],
        );

        if ($outsource->trashed()) {
            $outsource->restore();
        }

        if ($outsource->status !== 'active') {
            $outsource->status = 'active';
            $outsource->save();
        }

        return $outsource->refresh();
    }

    protected function resolveAssignment(int $outsourceId, int $storeId): OutsourceStoreAssignment
    {
        $assignment = OutsourceStoreAssignment::withTrashed()->firstOrCreate(
            ['outsource_id' => $outsourceId, 'store_id' => $storeId],
            ['status' => 'active'],
        );

        if ($assignment->trashed()) {
            $assignment->restore();
        }

        if ($assignment->status !== 'active') {
            $assignment->status = 'active';
            $assignment->save();
        }

        return $assignment->refresh();
    }

    protected function generateCityCode(string $name): string
    {
        $base = preg_replace('/[^A-Za-z0-9]+/', '-', strtoupper(trim($name)));
        $base = trim((string) $base, '-');

        if ($base === '') {
            $base = 'CITY';
        }

        return sprintf('%s-%s', $base, substr(md5($name), 0, 6));
    }

    protected function generateLocationCode(string $cityName, string $storeName): string
    {
        $base = sprintf('%s|%s', $cityName, $storeName);

        return 'LOC-' . substr(md5($base), 0, 12);
    }

    protected function makeKey(string $type, mixed ...$parts): string
    {
        return implode(':', [$type, ...array_map(fn($part) => (string) $part, $parts)]);
    }

    protected function resetState(): void
    {
        $this->normalizedCities = [];
        $this->normalizedStores = [];
        $this->normalizedOutsources = [];
        $this->normalizedAssignments = [];
    }
}
