<?php

namespace App\Services\Export;

use App\Models\OutsourceStoreAssignment;
use App\Models\WorkLocationPin;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;

/**
 * Writes the current outsource master data (DB = source of truth) to a
 * stores.json-compatible file in storage. Read-only against the database.
 *
 * The previous active file is rotated into backups/, so every run leaves a
 * versioned history: backups/… (older) → stores.json (latest from DB).
 *
 * Login PINs are never exported (the DB only holds hashes). Importing this
 * file elsewhere gives new persons the default PIN.
 */
class OutsourceStoresJsonExporter
{
    public const ACTIVE_PATH = 'outsource/stores.json';

    public const BACKUP_DIR = 'outsource/backups';

    public const STATUS_WRITTEN = 'written';

    public const STATUS_UNCHANGED = 'unchanged';

    public const STATUS_DRY_RUN = 'dry_run';

    /**
     * @return array{status: string, rows: int, persons: int, path: string, backup: string|null}
     */
    public function export(bool $dryRun = false): array
    {
        $rows = $this->rows();
        $json = json_encode($rows, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n";

        $result = [
            'rows' => count($rows),
            'persons' => count(array_unique(array_column($rows, 'employee_id'))),
            'path' => $this->disk()->path(self::ACTIVE_PATH),
            'backup' => null,
        ];

        if ($dryRun) {
            return ['status' => self::STATUS_DRY_RUN, ...$result];
        }

        $disk = $this->disk();

        if ($disk->exists(self::ACTIVE_PATH) && $disk->get(self::ACTIVE_PATH) === $json) {
            return ['status' => self::STATUS_UNCHANGED, ...$result];
        }

        if ($disk->exists(self::ACTIVE_PATH)) {
            $backup = $this->nextBackupPath($disk);
            $disk->move(self::ACTIVE_PATH, $backup);
            $result['backup'] = $disk->path($backup);
        }

        // Write-then-rename so a crash never leaves a half-written active file.
        $tmp = self::ACTIVE_PATH.'.tmp';
        $disk->put($tmp, $json);
        $disk->move($tmp, self::ACTIVE_PATH);

        return ['status' => self::STATUS_WRITTEN, ...$result];
    }

    /**
     * One row per (person, allowed pin), matching the stores.json schema read
     * by outsource:import. An empty allowlist means "all active cabang pins"
     * (same rule as check-in); those rows carry pin_scope=all_cabang_pins.
     *
     * @return list<array<string, mixed>>
     */
    public function rows(): array
    {
        $assignments = OutsourceStoreAssignment::query()
            ->where('status', 'active')
            ->whereHas('outsource')
            ->with([
                'outsource',
                'store.city',
                'store.activePins' => fn ($query) => $query->orderBy('name')->orderBy('id'),
                'assignmentPins',
                'pins' => fn ($query) => $query->orderBy('name')->orderBy('id'),
            ])
            ->get();

        $rows = [];

        foreach ($assignments as $assignment) {
            $outsource = $assignment->outsource;
            $cabang = $assignment->store;
            if ($cabang === null) {
                continue;
            }

            // Scope comes from the raw allowlist (as ResolveOutsourceAllowedPins):
            // a subset of only inactive/deleted pins still means "explicit"
            // (no pins allowed), not "all cabang pins".
            $scope = $assignment->assignmentPins->isNotEmpty() ? 'pin' : 'all_cabang_pins';
            /** @var Collection<int, WorkLocationPin> $pins */
            $pins = $scope === 'pin'
                ? $assignment->pins
                    ->where('work_location_id', $cabang->id)
                    ->where('status', 'active')
                    ->values()
                : $cabang->activePins;

            $base = [
                'city' => $cabang->city?->name ?? $cabang->name,
                'employee' => $outsource->name,
                'employee_id' => $outsource->outsource_code,
                'status' => $outsource->status,
            ];

            if ($pins->isEmpty()) {
                $rows[] = [
                    ...$base,
                    'store' => $cabang->name,
                    'pin_name' => $cabang->name,
                    'address' => null,
                    'lat' => null,
                    'lon' => null,
                    'radius_meters' => null,
                    'pin_scope' => $scope,
                    'is_fallback' => false,
                    'source' => 'db_export',
                ];

                continue;
            }

            foreach ($pins as $pin) {
                $rows[] = [
                    ...$base,
                    'store' => $pin->name,
                    'pin_name' => $pin->name,
                    'address' => $pin->address,
                    'lat' => $pin->latitude,
                    'lon' => $pin->longitude,
                    'radius_meters' => $pin->radius_meters,
                    'pin_scope' => $scope,
                    'is_fallback' => false,
                    'source' => 'db_export',
                ];
            }
        }

        usort($rows, static fn (array $a, array $b): int => [$a['city'], $a['employee_id'], $a['pin_name']]
            <=> [$b['city'], $b['employee_id'], $b['pin_name']]);

        return $rows;
    }

    private function nextBackupPath(FilesystemAdapter $disk): string
    {
        $stamp = now()->utc()->format('Ymd\THis\Z');
        $path = sprintf('%s/stores-%s.json', self::BACKUP_DIR, $stamp);

        for ($i = 2; $disk->exists($path); $i++) {
            $path = sprintf('%s/stores-%s-%d.json', self::BACKUP_DIR, $stamp, $i);
        }

        return $path;
    }

    private function disk(): FilesystemAdapter
    {
        /** @var FilesystemAdapter */
        return Storage::disk('local');
    }
}
