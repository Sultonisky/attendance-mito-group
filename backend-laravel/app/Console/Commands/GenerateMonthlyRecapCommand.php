<?php

namespace App\Console\Commands;

use App\Actions\MonthlyRecap\GenerateMonthlyRecap;
use App\Models\Employee;
use App\Models\MonthlyRecap;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

class GenerateMonthlyRecapCommand extends Command
{
    protected $signature = 'recap:generate {month? : Period in YYYY-MM format} {--employee= : Specific employee ID} {--force : Regenerate even if finalized}';

    protected $description = 'Generate monthly recaps for all active employees or a specific employee.';

    public function handle(): int
    {
        $monthInput = $this->argument('month');
        if ($monthInput === null) {
            $monthInput = CarbonImmutable::now()->subMonth()->format('Y-m');
        }

        if (! preg_match('/^\d{4}-\d{2}$/', $monthInput)) {
            $this->error('Invalid month format. Expected YYYY-MM, got: '.$monthInput);

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

        $employeeId = $this->option('employee');
        $force = $this->option('force');

        $total = 0;
        $failures = 0;

        if ($employeeId !== null) {
            $employees = Employee::where('id', (int) $employeeId)->whereNull('deleted_at')->get();
            foreach ($employees as $employee) {
                try {
                    $existing = MonthlyRecap::where('employee_id', $employee->id)
                        ->where('period', $monthInput)
                        ->first();

                    if ($existing !== null && in_array($existing->status, ['finalized', 'exported'], true) && ! $force) {
                        $this->warn("Skipping employee {$employee->id}: recap is {$existing->status}. Use --force to regenerate.");

                        continue;
                    }

                    app(GenerateMonthlyRecap::class)->execute($employee, $periodStart, $periodEnd, $actor);
                    $this->info("Generated recap for employee {$employee->id} ({$monthInput}).");
                    $total++;
                } catch (\Throwable $e) {
                    $this->error("Failed for employee {$employee->id}: ".$e->getMessage());
                    $failures++;
                }
            }
        } else {
            Employee::whereNull('deleted_at')->chunkById(100, function ($chunk) use ($periodStart, $periodEnd, $actor, $monthInput, $force, &$total, &$failures): void {
                foreach ($chunk as $employee) {
                    try {
                        $existing = MonthlyRecap::where('employee_id', $employee->id)
                            ->where('period', $monthInput)
                            ->first();

                        if ($existing !== null && in_array($existing->status, ['finalized', 'exported'], true) && ! $force) {
                            $this->warn("Skipping employee {$employee->id}: recap is {$existing->status}. Use --force to regenerate.");

                            continue;
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
