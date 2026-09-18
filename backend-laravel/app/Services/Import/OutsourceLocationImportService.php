<?php

namespace App\Services\Import;

use App\Models\City;
use App\Models\WorkLocation;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class OutsourceLocationImportService
{
    /**
     * Import verified store coordinates into existing work locations only.
     * Fallback city coordinates are intentionally rejected.
     *
     * @return array<string, mixed>
     */
    public function import(string $filePath, bool $dryRun = false, float $radiusMeters = 150, bool $allowPartial = false): array
    {
        $path = $this->resolvePath($filePath);
        $rows = json_decode((string) file_get_contents($path), true);

        if (! is_array($rows) || json_last_error() !== JSON_ERROR_NONE) {
            throw new RuntimeException('The location file must contain a valid JSON array.');
        }

        if ($radiusMeters <= 0) {
            throw new RuntimeException('The radius must be greater than zero.');
        }

        $summary = [
            'total_rows' => count($rows),
            'matched_rows' => 0,
            'updated_rows' => 0,
            'existing_rows' => 0,
            'fallback_rows' => 0,
            'invalid_rows' => 0,
            'unmatched_rows' => 0,
            'details' => [],
            'dry_run' => $dryRun,
            'allow_partial' => $allowPartial,
        ];
        $updates = [];

        foreach ($rows as $index => $row) {
            $rowNumber = $index + 1;
            if (! is_array($row)) {
                $this->reject($summary, $rowNumber, 'Row is not an object.');
                continue;
            }

            $cityName = $this->normalize((string) ($row['city'] ?? ''));
            $storeName = $this->normalize((string) ($row['store'] ?? ''));

            if ($cityName === '' || $storeName === '') {
                $this->reject($summary, $rowNumber, 'City or store is empty.');
                continue;
            }

            if ((bool) ($row['is_fallback'] ?? false) || ($row['source'] ?? null) === 'city_fallback') {
                $summary['fallback_rows']++;
                $summary['details'][] = ['row' => $rowNumber, 'reason' => 'City fallback coordinates are not importable.'];
                continue;
            }

            $latitude = filter_var($row['lat'] ?? null, FILTER_VALIDATE_FLOAT);
            $longitude = filter_var($row['lon'] ?? null, FILTER_VALIDATE_FLOAT);

            if ($latitude === false || $longitude === false || $latitude < -90 || $latitude > 90 || $longitude < -180 || $longitude > 180 || ($latitude == 0.0 && $longitude == 0.0)) {
                $this->reject($summary, $rowNumber, 'Invalid coordinates.');
                continue;
            }

            $city = City::query()->whereRaw('LOWER(name) = ?', [strtolower($cityName)])->first();
            $store = $city
                ? WorkLocation::query()->where('city_id', $city->id)->whereRaw('LOWER(name) = ?', [strtolower($storeName)])->first()
                : null;

            if ($store === null) {
                $summary['unmatched_rows']++;
                $summary['details'][] = ['row' => $rowNumber, 'reason' => "Store not found: {$cityName} / {$storeName}"];
                continue;
            }

            $summary['matched_rows']++;
            $alreadyCurrent = (float) $store->latitude === (float) $latitude
                && (float) $store->longitude === (float) $longitude
                && (float) ($store->radius_meters ?? 0) === $radiusMeters;

            if ($alreadyCurrent) {
                $summary['existing_rows']++;
            } else {
                $summary['updated_rows']++;
            }

            $updates[$store->id] = [
                'id' => $store->id,
                'latitude' => (float) $latitude,
                'longitude' => (float) $longitude,
            ];
        }

        if ($dryRun) {
            return $summary;
        }

        if (! $allowPartial && ($summary['fallback_rows'] > 0 || $summary['invalid_rows'] > 0 || $summary['unmatched_rows'] > 0)) {
            throw new RuntimeException('Location import blocked. Resolve fallback, invalid, and unmatched rows first.');
        }

        DB::transaction(function () use ($updates, $radiusMeters): void {
            foreach ($updates as $update) {
                WorkLocation::query()->whereKey($update['id'])->update([
                    'latitude' => $update['latitude'],
                    'longitude' => $update['longitude'],
                    'radius_meters' => $radiusMeters,
                ]);

                if (DB::connection()->getDriverName() === 'pgsql') {
                    DB::update(
                        'UPDATE work_locations SET location_point = ST_SetSRID(ST_MakePoint(?, ?), 4326) WHERE id = ?',
                        [$update['longitude'], $update['latitude'], $update['id']]
                    );
                }
            }
        });

        return $summary;
    }

    protected function resolvePath(string $filePath): string
    {
        $path = trim($filePath);
        if ($path === '') {
            throw new RuntimeException('The location file path is required.');
        }

        $absolutePath = $path;
        if (! str_starts_with($absolutePath, DIRECTORY_SEPARATOR) && ! str_contains($absolutePath, ':\\') && ! str_contains($absolutePath, ':/')) {
            $absolutePath = getcwd().DIRECTORY_SEPARATOR.$absolutePath;
        }

        if (! is_file($absolutePath)) {
            throw new RuntimeException("The location file was not found: {$absolutePath}");
        }

        return $absolutePath;
    }

    protected function normalize(string $value): string
    {
        return trim((string) preg_replace('/\s+/', ' ', $value));
    }

    /** @param array<string, mixed> $summary */
    protected function reject(array &$summary, int $rowNumber, string $reason): void
    {
        $summary['invalid_rows']++;
        $summary['details'][] = ['row' => $rowNumber, 'reason' => $reason];
    }
}