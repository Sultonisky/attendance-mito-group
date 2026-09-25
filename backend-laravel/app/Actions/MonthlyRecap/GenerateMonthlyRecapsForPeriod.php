<?php

namespace App\Actions\MonthlyRecap;

use App\Domain\MonthlyRecap\Exceptions\MonthlyRecapException;
use App\Models\Employee;
use App\Models\MonthlyRecap;
use App\Models\Outsource;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Bulk attendance-only monthly recap generation for a period + source.
 *
 * Skips finalized/exported rows (unless force). Continues on per-subject failures.
 */
class GenerateMonthlyRecapsForPeriod
{
    public function __construct(
        private GenerateMonthlyRecap $generate,
    ) {}

    /**
     * @return array{
     *   period: string,
     *   source: string,
     *   generated: int,
     *   skipped: int,
     *   failed: int,
     *   failures: list<array{id: int, message: string}>
     * }
     */
    public function execute(
        string $source,
        CarbonImmutable $periodStart,
        CarbonImmutable $periodEnd,
        User $actor,
        ?Request $request = null,
        bool $force = false,
        ?int $onlyEmployeeId = null,
        ?int $onlyOutsourceId = null,
    ): array {
        $period = $periodStart->format('Y-m');
        $generated = 0;
        $skipped = 0;
        $failed = 0;
        $failures = [];

        if ($source === 'outsource') {
            $query = Outsource::query()->whereNull('deleted_at')->where('status', 'active');
            if ($onlyOutsourceId !== null) {
                $query->whereKey($onlyOutsourceId);
            }

            $query->orderBy('id')->chunkById(100, function ($chunk) use (
                $periodStart,
                $periodEnd,
                $actor,
                $request,
                $period,
                $force,
                &$generated,
                &$skipped,
                &$failed,
                &$failures,
            ): void {
                foreach ($chunk as $outsource) {
                    $result = $this->generateOneOutsource($outsource, $periodStart, $periodEnd, $actor, $request, $period, $force);
                    $generated += $result['generated'];
                    $skipped += $result['skipped'];
                    $failed += $result['failed'];
                    if ($result['failure'] !== null) {
                        $failures[] = $result['failure'];
                    }
                }
            });
        } else {
            $query = Employee::query()->whereNull('deleted_at');
            if ($onlyEmployeeId !== null) {
                $query->whereKey($onlyEmployeeId);
            }

            $query->orderBy('id')->chunkById(100, function ($chunk) use (
                $periodStart,
                $periodEnd,
                $actor,
                $request,
                $period,
                $force,
                &$generated,
                &$skipped,
                &$failed,
                &$failures,
            ): void {
                foreach ($chunk as $employee) {
                    $result = $this->generateOneEmployee($employee, $periodStart, $periodEnd, $actor, $request, $period, $force);
                    $generated += $result['generated'];
                    $skipped += $result['skipped'];
                    $failed += $result['failed'];
                    if ($result['failure'] !== null) {
                        $failures[] = $result['failure'];
                    }
                }
            });
        }

        return [
            'period' => $period,
            'source' => $source,
            'generated' => $generated,
            'skipped' => $skipped,
            'failed' => $failed,
            'failures' => $failures,
        ];
    }

    /**
     * @return array{generated: int, skipped: int, failed: int, failure: array{id: int, message: string}|null}
     */
    private function generateOneEmployee(
        Employee $employee,
        CarbonImmutable $periodStart,
        CarbonImmutable $periodEnd,
        User $actor,
        ?Request $request,
        string $period,
        bool $force,
    ): array {
        try {
            $existing = MonthlyRecap::query()
                ->where('source', 'employee')
                ->where('employee_id', $employee->id)
                ->where('period', $period)
                ->first();

            if ($existing !== null && in_array($existing->status, ['finalized', 'exported'], true)) {
                if (! $force) {
                    return ['generated' => 0, 'skipped' => 1, 'failed' => 0, 'failure' => null];
                }
                $existing->update([
                    'status' => 'review',
                    'finalized_at' => null,
                    'exported_at' => null,
                ]);
            }

            $this->generate->execute($employee, $periodStart, $periodEnd, $actor, $request);

            return ['generated' => 1, 'skipped' => 0, 'failed' => 0, 'failure' => null];
        } catch (MonthlyRecapException $e) {
            return [
                'generated' => 0,
                'skipped' => 0,
                'failed' => 1,
                'failure' => ['id' => (int) $employee->id, 'message' => $e->getMessage()],
            ];
        } catch (\Throwable $e) {
            Log::warning('monthly_recap.bulk_generate_failed', [
                'source' => 'employee',
                'employee_id' => $employee->id,
                'period' => $period,
                'error' => $e->getMessage(),
            ]);

            return [
                'generated' => 0,
                'skipped' => 0,
                'failed' => 1,
                'failure' => ['id' => (int) $employee->id, 'message' => 'Failed to generate recap.'],
            ];
        }
    }

    /**
     * @return array{generated: int, skipped: int, failed: int, failure: array{id: int, message: string}|null}
     */
    private function generateOneOutsource(
        Outsource $outsource,
        CarbonImmutable $periodStart,
        CarbonImmutable $periodEnd,
        User $actor,
        ?Request $request,
        string $period,
        bool $force,
    ): array {
        try {
            $existing = MonthlyRecap::query()
                ->where('source', 'outsource')
                ->where('outsource_id', $outsource->id)
                ->where('period', $period)
                ->first();

            if ($existing !== null && in_array($existing->status, ['finalized', 'exported'], true)) {
                if (! $force) {
                    return ['generated' => 0, 'skipped' => 1, 'failed' => 0, 'failure' => null];
                }
                $existing->update([
                    'status' => 'review',
                    'finalized_at' => null,
                    'exported_at' => null,
                ]);
            }

            $this->generate->executeForOutsource($outsource, $periodStart, $periodEnd, $actor, $request);

            return ['generated' => 1, 'skipped' => 0, 'failed' => 0, 'failure' => null];
        } catch (MonthlyRecapException $e) {
            return [
                'generated' => 0,
                'skipped' => 0,
                'failed' => 1,
                'failure' => ['id' => (int) $outsource->id, 'message' => $e->getMessage()],
            ];
        } catch (\Throwable $e) {
            Log::warning('monthly_recap.bulk_generate_failed', [
                'source' => 'outsource',
                'outsource_id' => $outsource->id,
                'period' => $period,
                'error' => $e->getMessage(),
            ]);

            return [
                'generated' => 0,
                'skipped' => 0,
                'failed' => 1,
                'failure' => ['id' => (int) $outsource->id, 'message' => 'Failed to generate recap.'],
            ];
        }
    }
}
