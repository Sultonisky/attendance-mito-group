<?php

namespace App\Console\Commands;

use App\Actions\MonthlyRecap\GenerateMonthlyRecap;
use App\Models\Employee;
use App\Models\MonthlyRecap;
use App\Models\Outsource;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

class GenerateMonthlyRecapCommand extends Command
{
    protected $signature = 'recap:generate
        {month? : Period in YYYY-MM format}
        {--source=employee : employee|outsource}
        {--employee= : Specific employee ID}
        {--outsource= : Specific outsource ID}
        {--force : Regenerate even if finalized}';

    protected $description = 'Generate attendance-only monthly recaps for employees or outsources.';

    public function handle(): int
    {
        $monthInput = $this->argument('month') ?? CarbonImmutable::now()->subMonth()->format('Y-m');

        if (! preg_match('/^\d{4}-\d{2}$/', $monthInput)) {
            $this->error('Invalid month format. Expected YYYY-MM, got: '.$monthInput);

            return 1;
        }

        $source = (string) $this->option('source');
        if (! in_array($source, ['employee', 'outsource'], true)) {
            $this->error('Invalid --source. Use employee or outsource.');

            return 1;
        }

        [$year, $month] = explode('-', $monthInput);
        $periodStart = CarbonImmutable::create((int) $year, (int) $month, 1)->startOfDay();
        $periodEnd = $periodStart->endOfMonth();

        $actor = User::first();
        if ($actor === null) {
            $this->error('No user found to act as actor for audit.');

            return 1;
        }

        $force = (bool) $this->option('force');
        $total = 0;
        $failures = 0;

        if ($source === 'outsource') {
            $query = Outsource::query()->whereNull('deleted_at');
            if ($this->option('outsource') !== null) {
                $query->whereKey((int) $this->option('outsource'));
            }

            $query->chunkById(100, function ($chunk) use ($periodStart, $periodEnd, $actor, $monthInput, $force, &$total, &$failures): void {
                foreach ($chunk as $outsource) {
                    try {
                        $existing = MonthlyRecap::query()
                            ->where('source', 'outsource')
                            ->where('outsource_id', $outsource->id)
                            ->where('period', $monthInput)
                            ->first();

                        if ($existing !== null && in_array($existing->status, ['finalized', 'exported'], true) && ! $force) {
                            $this->warn("Skipping outsource {$outsource->id}: recap is {$existing->status}. Use --force to regenerate.");

                            continue;
                        }

                        if ($existing !== null && in_array($existing->status, ['finalized', 'exported'], true) && $force) {
                            $existing->update(['status' => 'review', 'finalized_at' => null, 'exported_at' => null]);
                        }

                        app(GenerateMonthlyRecap::class)->executeForOutsource($outsource, $periodStart, $periodEnd, $actor);
                        $this->info("Generated recap for outsource {$outsource->id} ({$monthInput}).");
                        $total++;
                    } catch (\Throwable $e) {
                        $this->error("Failed for outsource {$outsource->id}: ".$e->getMessage());
                        $failures++;
                    }
                }
            });
        } else {
            $query = Employee::query()->whereNull('deleted_at');
            if ($this->option('employee') !== null) {
                $query->whereKey((int) $this->option('employee'));
            }

            $query->chunkById(100, function ($chunk) use ($periodStart, $periodEnd, $actor, $monthInput, $force, &$total, &$failures): void {
                foreach ($chunk as $employee) {
                    try {
                        $existing = MonthlyRecap::query()
                            ->where('source', 'employee')
                            ->where('employee_id', $employee->id)
                            ->where('period', $monthInput)
                            ->first();

                        if ($existing !== null && in_array($existing->status, ['finalized', 'exported'], true) && ! $force) {
                            $this->warn("Skipping employee {$employee->id}: recap is {$existing->status}. Use --force to regenerate.");

                            continue;
                        }

                        if ($existing !== null && in_array($existing->status, ['finalized', 'exported'], true) && $force) {
                            $existing->update(['status' => 'review', 'finalized_at' => null, 'exported_at' => null]);
                        }

                        app(GenerateMonthlyRecap::class)->execute($employee, $periodStart, $periodEnd, $actor);
                        $this->info("Generated recap for employee {$employee->id} ({$monthInput}).");
                        $total++;
                    } catch (\Throwable $e) {
                        $this->error("Failed for employee {$employee->id}: ".$e->getMessage());
                        $failures++;
                    }
                }
            });
        }

        $this->info("Completed. Generated: {$total}, Failures: {$failures}.");

        return $failures > 0 ? 1 : 0;
    }
}
