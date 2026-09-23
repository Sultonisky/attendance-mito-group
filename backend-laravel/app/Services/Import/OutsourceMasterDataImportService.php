<?php

namespace App\Services\Import;

use App\Models\City;
use App\Models\Outsource;
use App\Models\OutsourceStoreAssignment;
use App\Models\WorkLocation;
use App\Models\WorkLocationPin;
use Illuminate\Support\Facades\DB;
use PhpOffice\PhpSpreadsheet\Reader\Xlsx as XlsxReader;
use RuntimeException;

/**
 * Unified outsource import from data/stores.json (preferred) or legacy CSV/XLSX.
 *
 * Model (locked for OS):
 * - City from LIST CABANG / city
 * - WorkLocation cabang = one per city (name = city name) — not per toko
 * - WorkLocationPin = address + lat/lng under that cabang
 * - Outsource assigned to cabang; optional pin allowlist from employee rows
 *
 * `store` / NAMA TOKO is metadata for pin naming only.
 */
class OutsourceMasterDataImportService
{
    /**
     * @return array<string, mixed>
     */
    public function import(
        string $filePath,
        bool $dryRun = false,
        float $radiusMeters = 150,
        bool $allowPartial = true,
    ): array {
        $absolutePath = $this->resolvePath($filePath);
        $extension = strtolower(pathinfo($absolutePath, PATHINFO_EXTENSION));

        $rows = match ($extension) {
            'json' => $this->readJsonRows($absolutePath),
            'csv', 'xlsx' => $this->readSpreadsheetAsUnifiedRows($absolutePath, $extension),
            default => throw new RuntimeException(sprintf('Unsupported format: %s. Use .json, .csv, or .xlsx.', $extension)),
        };

        $summary = [
            'total_rows' => count($rows),
            'valid_rows' => 0,
            'invalid_rows' => 0,
            'skipped_blank_rows' => 0,
            'pin_rows' => 0,
            'master_only_rows' => 0,
            'cities' => ['created' => 0, 'existing' => 0],
            'cabangs' => ['created' => 0, 'existing' => 0],
            // Alias for older command/tests that still say "stores"
            'stores' => ['created' => 0, 'existing' => 0],
            'outsources' => ['created' => 0, 'existing' => 0],
            'assignments' => ['created' => 0, 'existing' => 0],
            'pins_created' => 0,
            'pins_updated' => 0,
            'pins_existing' => 0,
            'assignment_pins_synced' => 0,
            'fallback_rows' => 0,
            'dry_run' => $dryRun,
            'allow_partial' => $allowPartial,
            'invalid_details' => [],
            'details' => [],
        ];

        $validRows = [];

        foreach ($rows as $index => $row) {
            $rowNumber = is_int($index) ? $index + 1 : (int) $index;
            if ($this->isBlankUnifiedRow($row)) {
                $summary['skipped_blank_rows']++;
                continue;
            }

            $normalized = $this->normalizeUnifiedRow($row, $rowNumber);
            if ($normalized['valid'] === false) {
                $summary['invalid_rows']++;
                $summary['invalid_details'][] = $normalized['error'];
                $summary['details'][] = [
                    'row' => $rowNumber,
                    'reason' => ($normalized['error']['field'] ?? 'field').' is empty',
                ];
                continue;
            }

            $data = $normalized['data'];
            if ($data['is_fallback']) {
                $summary['fallback_rows']++;
                $summary['details'][] = ['row' => $rowNumber, 'reason' => 'City fallback coordinates are not importable; importing master only.'];
                $data['latitude'] = null;
                $data['longitude'] = null;
            }

            if ($data['latitude'] !== null && $data['longitude'] !== null) {
                $summary['pin_rows']++;
            } else {
                $summary['master_only_rows']++;
            }

            $validRows[] = $data;
        }

        if ($summary['invalid_rows'] > 0 && ! $allowPartial) {
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

        if ($radiusMeters <= 0) {
            throw new RuntimeException('The radius must be greater than zero.');
        }

        $citySeen = [];
        $cabangSeen = [];
        $outsourceSeen = [];
        $assignmentSeen = [];
        /** @var array<string, list<int>> $allowlists keyed by "{outsource_id}:{cabang_id}" */
        $allowlists = [];
        /** @var array<string, true> $employeesWithPinRows */
        $employeesWithPinRows = [];

        DB::transaction(function () use (
            $validRows,
            $radiusMeters,
            &$summary,
            &$citySeen,
            &$cabangSeen,
            &$outsourceSeen,
            &$assignmentSeen,
            &$allowlists,
            &$employeesWithPinRows
        ) {
            foreach ($validRows as $row) {
                $city = $this->resolveCity($row['city_name']);
                $cabang = $this->resolveCabang($city);
                $outsource = $this->resolveOutsource($row['outsource_name']);
                $assignment = $this->resolveAssignment($outsource->id, $cabang->id);

                $cityKey = $this->makeKey('city', $city->name);
                $cabangKey = $this->makeKey('cabang', $cabang->id);
                $outsourceKey = $this->makeKey('outsource', $outsource->name);
                $assignmentKey = $this->makeKey('assignment', $outsource->id, $cabang->id);

                if (! isset($citySeen[$cityKey])) {
                    $citySeen[$cityKey] = $city->wasRecentlyCreated;
                }
                if (! isset($cabangSeen[$cabangKey])) {
                    $cabangSeen[$cabangKey] = $cabang->wasRecentlyCreated;
                }
                if (! isset($outsourceSeen[$outsourceKey])) {
                    $outsourceSeen[$outsourceKey] = $outsource->wasRecentlyCreated;
                }
                if (! isset($assignmentSeen[$assignmentKey])) {
                    $assignmentSeen[$assignmentKey] = $assignment->wasRecentlyCreated;
                }

                $allowKey = $outsource->id.':'.$cabang->id;
                $allowlists[$allowKey] ??= [];

                if ($row['latitude'] === null || $row['longitude'] === null) {
                    continue;
                }

                $employeesWithPinRows[$allowKey] = true;

                $pin = $this->upsertPin(
                    $cabang,
                    $row['pin_name'],
                    $row['address'],
                    $row['latitude'],
                    $row['longitude'],
                    $radiusMeters,
                    $row['radius_meters'],
                    $summary
                );

                $allowlists[$allowKey][] = (int) $pin->id;
            }

            foreach ($allowlists as $key => $pinIds) {
                [$outsourceId, $cabangId] = array_map('intval', explode(':', $key, 2));
                $assignment = OutsourceStoreAssignment::query()
                    ->where('outsource_id', $outsourceId)
                    ->where('store_id', $cabangId)
                    ->where('status', 'active')
                    ->whereNull('deleted_at')
                    ->first();

                if ($assignment === null) {
                    continue;
                }

                // Explicit pins in file => allowlist those only.
                // Master-only (no coords) => empty subset (= all active cabang pins).
                $unique = array_values(array_unique($pinIds));
                if (! isset($employeesWithPinRows[$key])) {
                    $unique = [];
                }

                $assignment->pins()->sync($unique);
                $summary['assignment_pins_synced']++;
            }

            $summary['cities']['created'] = count(array_filter($citySeen, fn ($c) => $c === true));
            $summary['cities']['existing'] = count(array_filter($citySeen, fn ($c) => $c === false));
            $summary['cabangs']['created'] = count(array_filter($cabangSeen, fn ($c) => $c === true));
            $summary['cabangs']['existing'] = count(array_filter($cabangSeen, fn ($c) => $c === false));
            $summary['stores'] = $summary['cabangs'];
            $summary['outsources']['created'] = count(array_filter($outsourceSeen, fn ($c) => $c === true));
            $summary['outsources']['existing'] = count(array_filter($outsourceSeen, fn ($c) => $c === false));
            $summary['assignments']['created'] = count(array_filter($assignmentSeen, fn ($c) => $c === true));
            $summary['assignments']['existing'] = count(array_filter($assignmentSeen, fn ($c) => $c === false));
        });

        return $summary;
    }

    /**
     * @return list<array<string, mixed>>
     */
    protected function readJsonRows(string $filePath): array
    {
        $decoded = json_decode((string) file_get_contents($filePath), true);
        if (! is_array($decoded) || json_last_error() !== JSON_ERROR_NONE) {
            throw new RuntimeException('The JSON file must contain a valid array of rows.');
        }

        $rows = [];
        foreach ($decoded as $row) {
            if (! is_array($row)) {
                continue;
            }
            $rows[] = $row;
        }

        return $rows;
    }

    /**
     * @return list<array<string, mixed>>
     */
    protected function readSpreadsheetAsUnifiedRows(string $filePath, string $extension): array
    {
        if ($extension === 'csv') {
            $handle = fopen($filePath, 'r');
            if ($handle === false) {
                throw new RuntimeException(sprintf('Unable to open file: %s', $filePath));
            }

            $header = null;
            $rows = [];
            while (($row = fgetcsv($handle)) !== false) {
                if ($row === [null] || $row === []) {
                    continue;
                }
                if ($header === null) {
                    $header = $this->canonicalizeHeaders(array_map(fn ($v) => $this->normalizeHeader($v), $row));
                    continue;
                }
                $rows[] = $this->spreadsheetRowToUnified(array_combine($header, $row) ?: []);
            }
            fclose($handle);

            return $rows;
        }

        $reader = new XlsxReader;
        $spreadsheet = $reader->load($filePath);
        $worksheet = $spreadsheet->getActiveSheet();
        $raw = $worksheet->toArray();
        if (count($raw) < 2) {
            throw new RuntimeException('The Excel file does not contain any data rows.');
        }

        $header = $this->canonicalizeHeaders(array_map(fn ($v) => $this->normalizeHeader($v), $raw[0]));
        $expected = ['LIST CABANG', 'NAMA TOKO', 'NAMA SPG'];
        if (! empty(array_diff($expected, $header))) {
            throw new RuntimeException('Missing headers: LIST CABANG, NAMA TOKO, NAMA SPG (or NAMA KARYAWAN).');
        }

        $rows = [];
        for ($i = 1; $i < count($raw); $i++) {
            $rows[] = $this->spreadsheetRowToUnified(array_combine($header, $raw[$i]) ?: []);
        }

        return $rows;
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    protected function spreadsheetRowToUnified(array $row): array
    {
        return [
            'city' => $row['LIST CABANG'] ?? null,
            'store' => $row['NAMA TOKO'] ?? null,
            'employee' => $row['NAMA SPG'] ?? $row['NAMA KARYAWAN'] ?? null,
            'pin_name' => $row['NAMA TOKO'] ?? null,
            'address' => $row['Alamat'] ?? $row['address'] ?? null,
            'lat' => $row['lat'] ?? $row['LATITUDE'] ?? null,
            'lon' => $row['lon'] ?? $row['LONGITUDE'] ?? null,
            'is_fallback' => false,
            'source' => 'spreadsheet',
        ];
    }

    /**
     * @param  array<string, mixed>  $row
     */
    protected function isBlankUnifiedRow(array $row): bool
    {
        foreach (['city', 'store', 'employee', 'LIST CABANG', 'NAMA TOKO', 'NAMA SPG', 'NAMA KARYAWAN'] as $key) {
            if ($this->normalizeString($row[$key] ?? null) !== null) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array{valid: bool, data?: array<string, mixed>, error?: array<string, mixed>}
     */
    protected function normalizeUnifiedRow(array $row, int $rowNumber): array
    {
        $city = $this->normalizeString($row['city'] ?? $row['LIST CABANG'] ?? null);
        $employee = $this->normalizeString($row['employee'] ?? $row['NAMA SPG'] ?? $row['NAMA KARYAWAN'] ?? null);
        $store = $this->normalizeString($row['store'] ?? $row['NAMA TOKO'] ?? null);
        $pinName = $this->normalizeString($row['pin_name'] ?? null) ?? $store ?? $city;
        $address = $this->normalizeString($row['address'] ?? $row['Alamat'] ?? null);

        if ($city === null || $employee === null) {
            return [
                'valid' => false,
                'error' => [
                    'row_number' => $rowNumber,
                    'field' => $city === null ? 'city' : 'employee',
                    'reason' => 'required value is missing',
                ],
            ];
        }

        $latRaw = $row['lat'] ?? $row['latitude'] ?? null;
        $lonRaw = $row['lon'] ?? $row['longitude'] ?? null;
        $latitude = filter_var($latRaw, FILTER_VALIDATE_FLOAT, FILTER_NULL_ON_FAILURE);
        $longitude = filter_var($lonRaw, FILTER_VALIDATE_FLOAT, FILTER_NULL_ON_FAILURE);

        if ($latitude !== null && $longitude !== null) {
            if ($latitude < -90 || $latitude > 90 || $longitude < -180 || $longitude > 180 || ($latitude == 0.0 && $longitude == 0.0)) {
                $latitude = null;
                $longitude = null;
            }
        } else {
            $latitude = null;
            $longitude = null;
        }

        $isFallback = (bool) ($row['is_fallback'] ?? false) || ($row['source'] ?? null) === 'city_fallback';

        $explicitRadius = null;
        if (array_key_exists('radius_meters', $row) && $row['radius_meters'] !== null && $row['radius_meters'] !== '') {
            $parsedRadius = filter_var($row['radius_meters'], FILTER_VALIDATE_FLOAT, FILTER_NULL_ON_FAILURE);
            if ($parsedRadius !== null && $parsedRadius > 0) {
                $explicitRadius = (float) $parsedRadius;
            }
        }

        return [
            'valid' => true,
            'data' => [
                'city_name' => $this->normalizeName($city),
                'outsource_name' => $this->normalizeName($employee),
                'store_meta' => $store !== null ? $this->normalizeName($store) : null,
                'pin_name' => $this->normalizeName((string) $pinName),
                'address' => $address,
                'latitude' => $latitude,
                'longitude' => $longitude,
                'radius_meters' => $explicitRadius,
                'is_fallback' => $isFallback,
            ],
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $validRows
     * @param  array<string, mixed>  $summary
     * @return array<string, mixed>
     */
    protected function buildDryRunSummary(array $validRows, array $summary): array
    {
        $cities = [];
        $cabangs = [];
        $outsources = [];
        $assignments = [];
        $pinKeys = [];

        foreach ($validRows as $row) {
            $cities[$row['city_name']] = true;
            $cabangs[$row['city_name']] = true;
            $outsources[$row['outsource_name']] = true;
            $assignments[$row['city_name'].'|'.$row['outsource_name']] = true;
            if ($row['latitude'] !== null && $row['longitude'] !== null) {
                $pinKeys[$row['city_name'].'|'.round($row['latitude'], 7).'|'.round($row['longitude'], 7)] = true;
            }
        }

        $summary['cities']['created'] = count($cities);
        $summary['cities']['existing'] = 0;
        $summary['cabangs']['created'] = count($cabangs);
        $summary['cabangs']['existing'] = 0;
        $summary['stores'] = $summary['cabangs'];
        $summary['outsources']['created'] = count($outsources);
        $summary['outsources']['existing'] = 0;
        $summary['assignments']['created'] = count($assignments);
        $summary['assignments']['existing'] = 0;
        $summary['pins_created'] = count($pinKeys);

        return $summary;
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

    /**
     * One WorkLocation cabang per city (name matches city).
     */
    protected function resolveCabang(City $city): WorkLocation
    {
        $cabang = WorkLocation::withTrashed()->firstOrCreate(
            ['city_id' => $city->id, 'name' => $city->name],
            [
                'code' => $this->generateLocationCode($city->name, $city->name),
                'latitude' => null,
                'longitude' => null,
                'radius_meters' => null,
                'status' => 'active',
            ],
        );

        if ($cabang->trashed()) {
            $cabang->restore();
        }

        if ($cabang->status !== 'active') {
            $cabang->status = 'active';
            $cabang->save();
        }

        return $cabang->refresh();
    }

    protected function resolveOutsource(string $name): Outsource
    {
        $outsource = Outsource::withTrashed()->firstOrCreate(
            ['name' => $name],
            [
                'outsource_code' => Outsource::generateNextCode(),
                'status' => 'active',
                'password' => Outsource::DEFAULT_LOGIN_PIN,
            ],
        );

        if ($outsource->trashed()) {
            $outsource->restore();
        }

        $dirty = false;

        if ($outsource->status !== 'active') {
            $outsource->status = 'active';
            $dirty = true;
        }

        // Existing rows may have been imported before password existed.
        if (! filled($outsource->getRawOriginal('password'))) {
            $outsource->password = Outsource::DEFAULT_LOGIN_PIN;
            $dirty = true;
        }

        if ($dirty) {
            $outsource->save();
        }

        return $outsource->refresh();
    }

    protected function resolveAssignment(int $outsourceId, int $cabangId): OutsourceStoreAssignment
    {
        $assignment = OutsourceStoreAssignment::withTrashed()->firstOrCreate(
            ['outsource_id' => $outsourceId, 'store_id' => $cabangId],
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

    /**
     * @param  array<string, mixed>  $summary
     */
    protected function upsertPin(
        WorkLocation $cabang,
        string $pinName,
        ?string $address,
        float $latitude,
        float $longitude,
        float $defaultRadiusMeters,
        ?float $explicitRadiusMeters,
        array &$summary,
    ): WorkLocationPin {
        $pin = $this->findPin((int) $cabang->id, $latitude, $longitude);
        $createRadius = $explicitRadiusMeters ?? $defaultRadiusMeters;

        if ($pin === null) {
            $pin = WorkLocationPin::create([
                'work_location_id' => $cabang->id,
                'name' => $pinName,
                'address' => $address,
                'latitude' => $latitude,
                'longitude' => $longitude,
                'radius_meters' => $createRadius,
                'status' => 'active',
            ]);
            $this->syncLocationPoint($pin, $latitude, $longitude);
            $summary['pins_created']++;

            return $pin;
        }

        $nextRadius = $explicitRadiusMeters ?? (float) ($pin->radius_meters ?? $defaultRadiusMeters);

        $needsUpdate = $pin->name !== $pinName
            || (string) ($pin->address ?? '') !== (string) ($address ?? '')
            || (float) $pin->latitude !== $latitude
            || (float) $pin->longitude !== $longitude
            || (float) ($pin->radius_meters ?? 0) !== $nextRadius
            || $pin->status !== 'active';

        if ($needsUpdate) {
            $pin->fill([
                'name' => $pinName,
                'address' => $address ?? $pin->address,
                'latitude' => $latitude,
                'longitude' => $longitude,
                'radius_meters' => $nextRadius,
                'status' => 'active',
            ]);
            $pin->save();
            $this->syncLocationPoint($pin, $latitude, $longitude);
            $summary['pins_updated']++;
        } else {
            $summary['pins_existing']++;
        }

        return $pin;
    }

    protected function findPin(int $cabangId, float $latitude, float $longitude): ?WorkLocationPin
    {
        $targetLat = round($latitude, 7);
        $targetLon = round($longitude, 7);

        return WorkLocationPin::query()
            ->where('work_location_id', $cabangId)
            ->get()
            ->first(static function (WorkLocationPin $pin) use ($targetLat, $targetLon): bool {
                return round((float) $pin->latitude, 7) === $targetLat
                    && round((float) $pin->longitude, 7) === $targetLon;
            });
    }

    protected function syncLocationPoint(WorkLocationPin $pin, float $lat, float $lng): void
    {
        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::update(
                'UPDATE work_location_pins SET location_point = ST_SetSRID(ST_MakePoint(?, ?), 4326) WHERE id = ?',
                [$lng, $lat, $pin->id]
            );
        }
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
            $absolutePath = getcwd().DIRECTORY_SEPARATOR.$absolutePath;
        }

        if (! file_exists($absolutePath)) {
            throw new RuntimeException(sprintf('The import file was not found: %s', $absolutePath));
        }

        return $absolutePath;
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

        return 'LOC-'.substr(md5($base), 0, 12);
    }

    protected function makeKey(string $type, mixed ...$parts): string
    {
        return implode(':', [$type, ...array_map(fn ($part) => (string) $part, $parts)]);
    }
}
