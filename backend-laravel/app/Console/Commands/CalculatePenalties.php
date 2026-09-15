<?php

namespace App\Console\Commands;

use App\Actions\Penalty\CalculateSystemPenalty;
use App\Models\Employee;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

class CalculatePenalties extends Command
{
    protected $signature = 'penalty:calculate 
                            {--date= : Specific date (Y-m-d)}
                            {--from= : Start date (Y-m-d)}
                            {--to= : End date (Y-m-d)}
                            {--employee= : Specific employee ID}';

    protected $description = 'Calculate system penalties for employees.';

    public function handle(CalculateSystemPenalty $action): int
    {
        $date = $this->option('date') ? CarbonImmutable::parse($this->option('date')) : CarbonImmutable::now();
        $from = $this->option('from') ? CarbonImmutable::parse($this->option('from')) : $date;
        $to = $this->option('to') ? CarbonImmutable::parse($this->option('to')) : $date;

        if ($from->greaterThan($to)) {
            $this->error('From date must be before or equal to to date.');

            return 1;
        }

        $employeeId = $this->option('employee');
        $query = Employee::query();
        if ($employeeId) {
            $query->where('id', $employeeId);
        }

        $totalCreated = 0;

        $query->orderBy('id')->chunkById(100, function ($employees) use ($from, $to, $action, &$totalCreated): void {
            foreach ($employees as $employee) {
                for ($current = $from; $current->lessThanOrEqualTo($to); $current = $current->addDay()) {
                    try {
                        $records = $action->execute($employee, $current);
                        $totalCreated += count($records);
                    } catch (\Throwable $e) {
                        $this->error('Failed for employee '.$employee->id.' on '.$current->toDateString().': '.$e->getMessage());
                    }
                }
            }
        });

        $this->info('Calculated '.$totalCreated.' penalty records.');

        return 0;
    }
}
