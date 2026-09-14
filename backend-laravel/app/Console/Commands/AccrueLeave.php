<?php

namespace App\Console\Commands;

use App\Actions\Leave\AccrueLeaveBalance;
use App\Models\Employee;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

/**
 * Idempotent monthly accrual across active employees. Safe to rerun.
 */
class AccrueLeave extends Command
{
    protected $signature = 'leave:accrue {--date= : Accrual reference date (Y-m-d)}';

    protected $description = 'Accrue monthly annual leave for eligible employees';

    public function handle(AccrueLeaveBalance $action): int
    {
        $asOf = $this->option('date') !== null ? CarbonImmutable::parse($this->option('date'))->startOfDay() : CarbonImmutable::now();
        $employees = 0;
        $created = 0;
        Employee::whereNull('deleted_at')->chunkById(200, function ($chunk) use ($asOf, $action, &$employees, &$created): void {
            foreach ($chunk as $employee) {
                $employees++;
                try {
                    $created += $action->execute($employee, $asOf, null);
                } catch (\Throwable $e) {
                    $this->warn("Skipped employee {$employee->id}: {$e->getMessage()}");
                }
            }
        });
        $this->info("Accrual complete: {$employees} employees, {$created} accrual transactions.");

        return self::SUCCESS;
    }
}
