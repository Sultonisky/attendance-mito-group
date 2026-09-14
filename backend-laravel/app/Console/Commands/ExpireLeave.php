<?php

namespace App\Console\Commands;

use App\Actions\Leave\ExpireLeaveBalance;
use App\Models\Employee;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

/**
 * Idempotent expiry across employees with positive balances. Safe to rerun.
 */
class ExpireLeave extends Command
{
    protected $signature = 'leave:expire {--date= : Expiry reference date (Y-m-d)}';

    protected $description = 'Expire annual leave batches past the 12-month retention';

    public function handle(ExpireLeaveBalance $action): int
    {
        $asOf = $this->option('date') !== null ? CarbonImmutable::parse($this->option('date'))->startOfDay() : CarbonImmutable::now();
        $employees = 0;
        $expired = 0;
        Employee::whereNull('deleted_at')->chunkById(200, function ($chunk) use ($asOf, $action, &$employees, &$expired): void {
            foreach ($chunk as $employee) {
                $employees++;
                $expired += $action->execute($employee, $asOf, null);
            }
        });
        $this->info("Expiry complete: {$employees} employees, {$expired} expiration transactions.");

        return self::SUCCESS;
    }
}
