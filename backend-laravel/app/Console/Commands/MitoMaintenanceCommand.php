<?php

namespace App\Console\Commands;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Throwable;

/**
 * Unified maintenance switch for Laravel API + Vue SPA.
 *
 * down  → artisan down (Blade for cold loads) + public/maintenance.json (Vue/Vite)
 * up    → artisan up + remove flag files
 * status → show whether maintenance is active
 *
 * Local Vite reads frontend/public/maintenance.json.
 * Production reads public/maintenance.json (same origin as the SPA).
 */
#[Signature('mito:maintenance {action : down|up|status} {--retry=60 : Retry-After seconds for API clients} {--secret= : Optional bypass secret for artisan down} {--message="Sedang Dalam Pemeliharaan" : Maintenance message shown to clients}')]
#[Description('Enable/disable maintenance for Laravel and the Vue SPA together.')]
class MitoMaintenanceCommand extends Command
{
    private const FLAG_RELATIVE = 'maintenance.json';

    public function handle(): int
    {
        $action = strtolower((string) $this->argument('action'));

        return match ($action) {
            'down' => $this->enable(),
            'up' => $this->disable(),
            'status' => $this->status(),
            default => $this->invalidAction($action),
        };
    }

    private function enable(): int
    {
        $retry = max(1, (int) $this->option('retry'));
        $message = (string) $this->option('message');
        $secret = $this->option('secret');

        $payload = [
            'enabled' => true,
            'retry_after' => $retry,
            'message' => $message,
            'enabled_at' => now()->toIso8601String(),
        ];

        $written = $this->writeFlagFiles($payload);
        if ($written === 0) {
            $this->error('Could not write any maintenance flag file.');

            return self::FAILURE;
        }

        $downOptions = [
            '--render' => 'errors.maintenance',
            '--retry' => $retry,
        ];

        if (is_string($secret) && $secret !== '') {
            $downOptions['--secret'] = $secret;
        }

        try {
            $exit = Artisan::call('down', $downOptions);
            $this->output->write(Artisan::output());

            if ($exit !== self::SUCCESS) {
                // Fallback without custom Blade render (older images / missing view).
                $fallback = ['--retry' => $retry];
                if (is_string($secret) && $secret !== '') {
                    $fallback['--secret'] = $secret;
                }
                $exit = Artisan::call('down', $fallback);
                $this->output->write(Artisan::output());
            }
        } catch (Throwable $e) {
            $this->error('Laravel down failed: '.$e->getMessage());
            $this->removeFlagFiles();

            return self::FAILURE;
        }

        $this->newLine();
        $this->info('Maintenance ON (Laravel + Vue flag).');
        $this->line('  • Cold load via Laravel  → Blade errors/maintenance');
        $this->line('  • Vue / Vite SPA         → /maintenance.json → page 503');
        $this->line('  • API clients            → HTTP 503 + Retry-After: '.$retry);
        if (is_string($secret) && $secret !== '') {
            $this->line('  • Bypass secret path     → /'.$secret);
        }
        $this->newLine();
        $this->comment('Local Vite: refresh the browser (or open any route) to pick up the flag.');

        return self::SUCCESS;
    }

    private function disable(): int
    {
        $upOk = false;

        try {
            $exit = Artisan::call('up');
            $this->output->write(Artisan::output());
            $upOk = $exit === self::SUCCESS;
        } catch (Throwable $e) {
            $this->warn('artisan up: '.$e->getMessage());
        }

        $removed = $this->removeFlagFiles();

        $this->newLine();
        if (! $upOk) {
            $this->error('Maintenance flag files cleaned, but artisan up did not succeed.');
            $this->comment('Run: php artisan up');

            return self::FAILURE;
        }

        $this->info('Maintenance OFF.');
        $this->line(sprintf('  Removed %d flag file(s).', $removed));
        $this->comment('Local Vite: refresh once if the maintenance page is still open.');

        return self::SUCCESS;
    }

    private function status(): int
    {
        $laravelDown = app()->isDownForMaintenance();
        $flags = $this->flagPaths();

        $rows = [
            ['laravel', $laravelDown ? 'DOWN' : 'UP', $laravelDown ? 'storage/framework/down' : '—'],
        ];

        foreach ($flags as $label => $path) {
            $active = false;
            $detail = 'missing';

            if (is_file($path)) {
                $json = json_decode((string) file_get_contents($path), true);
                $active = is_array($json) && ($json['enabled'] ?? false) === true;
                $detail = $path;
            }

            $rows[] = [$label, $active ? 'FLAG ON' : 'FLAG OFF', $detail];
        }

        $this->table(['Layer', 'State', 'Detail'], $rows);

        return self::SUCCESS;
    }

    private function invalidAction(string $action): int
    {
        $this->error("Unknown action [{$action}]. Use: down, up, or status.");

        return self::FAILURE;
    }

    /**
     * @param  array{enabled: bool, retry_after: int, message: string, enabled_at: string}  $payload
     */
    private function writeFlagFiles(array $payload): int
    {
        $json = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n";
        $count = 0;

        foreach ($this->flagPaths() as $path) {
            $dir = dirname($path);
            if (! is_dir($dir)) {
                continue;
            }

            if (File::put($path, $json) !== false) {
                $this->line('  Wrote '.$path);
                $count++;
            }
        }

        return $count;
    }

    private function removeFlagFiles(): int
    {
        $count = 0;

        foreach ($this->flagPaths() as $path) {
            if (is_file($path)) {
                File::delete($path);
                $this->line('  Removed '.$path);
                $count++;
            }
        }

        return $count;
    }

    /**
     * Laravel public (prod + artisan serve) and frontend/public (local Vite).
     *
     * @return array<string, string>
     */
    private function flagPaths(): array
    {
        $paths = [
            'laravel-public' => public_path(self::FLAG_RELATIVE),
        ];

        // Monorepo local: backend-laravel/../frontend/public
        $vitePublic = dirname(base_path()).DIRECTORY_SEPARATOR.'frontend'.DIRECTORY_SEPARATOR.'public'.DIRECTORY_SEPARATOR.self::FLAG_RELATIVE;
        $paths['vite-public'] = $vitePublic;

        return $paths;
    }
}
