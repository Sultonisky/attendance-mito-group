<?php

namespace App\Domain\Leave\Engines;

use App\Domain\Leave\DTOs\LeaveBalanceData;
use App\Domain\Leave\DTOs\LeaveEligibilityData;
use App\Domain\Leave\DTOs\LeaveResolutionData;
use App\Domain\Leave\Exceptions\InsufficientLeaveBalanceException;
use App\Domain\Leave\Exceptions\InvalidLeaveStateException;
use App\Domain\Leave\Exceptions\LeaveNotEligibleException;
use App\Domain\Leave\Exceptions\OverlappingLeaveException;
use App\Domain\Leave\Rules\LeaveDateRule;
use App\Enums\LeaveTransactionType;
use App\Models\Employee;
use App\Models\LeaveBalance;
use App\Models\LeaveRequest;
use App\Models\LeaveTransaction;
use App\Models\LeaveType;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * Authoritative leave domain decisions.
 *
 * Pure calculations never touch HTTP. Mutating helpers perform row writes but
 * NEVER open their own transaction: Actions/Commands own DB::transaction().
 *
 * Month-end policy: accruals occur on the join-date day clamped to the last
 * day of shorter months (Jan 31 -> Feb 28/29). Expiry is accrual + 12 months
 * (same clamp). A batch is available ON its expiry date; it expires after
 * (expires_at < asOf triggers expiry).
 */
class LeaveEngine
{
    public const ANNUAL_CODE = 'annual_leave';

    public const ELIGIBILITY_MONTHS = 6;

    public const MONTHLY_ACCRUAL_DAYS = 1.0;

    public const EXPIRY_MONTHS = 12;

    public function __construct(private LeaveDateRule $dates) {}

    public function isActive(Employee $employee, ?CarbonImmutable $asOf = null): bool
    {
        $asOf ??= CarbonImmutable::now();
        if ($employee->trashed()) {
            return false;
        }
        $rawEnd = $employee->getAttribute('end_date');
        $end = $rawEnd !== null ? CarbonImmutable::parse($rawEnd)->startOfDay() : null;

        return $end === null || ! $end->lessThan($asOf->startOfDay());
    }

    public function eligibilityDate(?string $joinDate): ?CarbonImmutable
    {
        if ($joinDate === null || $joinDate === '') {
            return null;
        }

        $eligibility = CarbonImmutable::parse($joinDate)->startOfDay()->addMonthsNoOverflow(self::ELIGIBILITY_MONTHS);

        return $eligibility->startOfDay();
    }

    public function resolveEligibility(Employee $employee, ?CarbonImmutable $asOf = null): LeaveEligibilityData
    {
        $asOf ??= CarbonImmutable::now();
        $rawJoin = $employee->getAttribute('join_date');
        $joinStr = $rawJoin !== null ? CarbonImmutable::parse($rawJoin)->toDateString() : '';
        if ($joinStr === '') {
            return new LeaveEligibilityData($employee->id, null, null, false, $this->isActive($employee, $asOf), 'Employee has no join date.');
        }
        $join = CarbonImmutable::parse($joinStr)->startOfDay();
        $eligibility = $this->eligibilityDate($joinStr);
        $active = $this->isActive($employee, $asOf);
        $eligible = $active && ! $asOf->startOfDay()->lessThan($eligibility);
        $reason = ! $active ? 'Employee is not active.' : ($eligible ? 'Eligible for annual leave.' : 'Join date + 6 months not yet reached.');

        return new LeaveEligibilityData($employee->id, $join, $eligibility, $eligible, $active, $reason);
    }

    public function annualLeaveType(): ?LeaveType
    {
        return LeaveType::where('code', self::ANNUAL_CODE)->first()
            ?? LeaveType::where('category', 'annual')->where('status', 'active')->first();
    }

    public function consumesAnnualBalance(LeaveType $type): bool
    {
        return $type->category === 'annual' || (bool) $type->deducts_annual_balance;
    }

    public function accrualDateForMonth(CarbonImmutable $joinDate, int $year, int $month): CarbonImmutable
    {
        $day = min($joinDate->day, CarbonImmutable::create($year, $month, 1)->daysInMonth);

        return CarbonImmutable::create($year, $month, $day)->startOfDay();
    }

    /**
     * @return list<CarbonImmutable>
     */
    public function dueAccrualDates(Employee $employee, CarbonImmutable $asOf): array
    {
        $rawJoin = $employee->getAttribute('join_date');
        $joinStr = $rawJoin !== null ? CarbonImmutable::parse($rawJoin)->toDateString() : '';
        if ($joinStr === '') {
            return [];
        }
        $join = CarbonImmutable::parse($joinStr)->startOfDay();
        $eligibility = $this->eligibilityDate($joinStr);
        $asOfDay = $asOf->startOfDay();
        if ($asOfDay->lessThan($eligibility)) {
            return [];
        }
        $dates = [];
        $cursor = $eligibility->startOfMonth();
        $endMonth = $asOfDay->startOfMonth();
        while (! $cursor->greaterThan($endMonth)) {
            $accrual = $this->accrualDateForMonth($join, $cursor->year, $cursor->month);
            if (! $accrual->greaterThan($asOfDay) && ! $accrual->lessThan($eligibility)) {
                $dates[] = $accrual;
            }
            $cursor = $cursor->addMonthNoOverflow()->startOfMonth();
        }

        return $dates;
    }

    public function expiryForAccrual(CarbonImmutable $accrualDate): CarbonImmutable
    {
        return $accrualDate->addMonthsNoOverflow(self::EXPIRY_MONTHS)->startOfDay();
    }

    public function assertNoOverlap(Employee $employee, CarbonImmutable $start, CarbonImmutable $end, ?int $ignoreId = null): void
    {
        if ($this->dates->findOverlap($employee, $start, $end, $ignoreId) !== null) {
            throw new OverlappingLeaveException('Employee already has pending or approved leave overlapping these dates.');
        }
    }

    /**
     * Compensating reversal for a cancelled approved request. History kept.
     *
     * @return list<LeaveTransaction>
     */
    public function reverseConsumption(LeaveRequest $request, CarbonImmutable $occurredAt, ?int $actorId = null): array
    {
        $consumptions = LeaveTransaction::where('leave_request_id', $request->id)
            ->where('transaction_type', LeaveTransactionType::Consumption->value)
            ->orderBy('id')->lockForUpdate()->get();
        $reversals = [];
        foreach ($consumptions as $consumption) {
            $already = LeaveTransaction::where('leave_request_id', $request->id)
                ->where('transaction_type', LeaveTransactionType::Reversal->value)
                ->where('leave_balance_id', $consumption->leave_balance_id)
                ->where('amount', -1 * (float) $consumption->amount)->exists();
            if ($already) {
                continue;
            }
            $balance = LeaveBalance::whereKey($consumption->leave_balance_id)->lockForUpdate()->first();
            if ($balance === null) {
                continue;
            }
            $restore = -1 * (float) $consumption->amount;
            $balance->balance = (float) $balance->balance + $restore;
            $balance->save();
            $reversals[] = LeaveTransaction::create([
                'employee_id' => $request->employee_id, 'leave_balance_id' => $balance->id,
                'leave_request_id' => $request->id, 'transaction_type' => LeaveTransactionType::Reversal->value,
                'amount' => $restore, 'balance_after' => (float) $balance->balance,
                'reason' => 'Leave cancellation reversal', 'occurred_at' => $occurredAt,
                'metadata' => ['leave_request_id' => $request->id, 'leave_transaction_id' => $consumption->id, 'actor_id' => $actorId],
            ]);
        }

        return $reversals;
    }

    public function resolveForDate(Employee $employee, CarbonImmutable $date): LeaveResolutionData
    {
        $request = LeaveRequest::where('employee_id', $employee->id)->where('status', 'approved')
            ->whereDate('start_date', '<=', $date->toDateString())->whereDate('end_date', '>=', $date->toDateString())
            ->orderBy('start_date')->first();

        return new LeaveResolutionData($employee->id, $date->startOfDay(), $request !== null, $request?->id);
    }

    /**
     * Idempotent expiry of batches past retention. Returns expiration rows.
     *
     * @return list<LeaveTransaction>
     */
    public function expire(Employee $employee, CarbonImmutable $asOf, ?int $actorId = null): array
    {
        $expired = [];
        $rows = LeaveBalance::where('employee_id', $employee->id)->where('balance', '>', 0)
            ->whereNotNull('expires_at')->whereDate('expires_at', '<', $asOf->toDateString())
            ->orderBy('expires_at')->lockForUpdate()->get();
        foreach ($rows as $balance) {
            $amount = -1 * (float) $balance->balance;
            $balance->balance = 0;
            $balance->save();
            $expired[] = LeaveTransaction::create([
                'employee_id' => $employee->id, 'leave_balance_id' => $balance->id,
                'transaction_type' => LeaveTransactionType::Expiration->value,
                'amount' => $amount, 'balance_after' => 0,
                'reason' => 'Annual leave expired after 12 months', 'occurred_at' => $asOf,
                'metadata' => ['period' => $balance->period, 'actor_id' => $actorId],
            ]);
        }

        return $expired;
    }

    /**
     * FIFO consumption across oldest non-expired batches.
     *
     * @return list<LeaveTransaction>
     */
    public function consume(Employee $employee, LeaveType $annualType, int $days, LeaveRequest $request, CarbonImmutable $occurredAt): array
    {
        if ($days <= 0) {
            throw new InvalidLeaveStateException('Leave duration must be positive.');
        }
        $driver = DB::getDriverName();
        $query = LeaveBalance::where('employee_id', $employee->id)->where('leave_type_id', $annualType->id)
            ->where('balance', '>', 0)
            ->where(function ($q) use ($occurredAt): void {
                $q->whereNull('expires_at')->orWhereDate('expires_at', '>=', $occurredAt->toDateString());
            });
        if ($driver === 'sqlite') {
            $query = $query->orderByRaw('expires_at IS NULL, expires_at ASC')->orderBy('id');
        } else {
            $query = $query->orderByRaw('expires_at ASC NULLS LAST')->orderBy('id');
        }
        $batches = $query->lockForUpdate()->get();
        $remaining = (float) $days;
        $available = $batches->sum(fn ($b): float => (float) $b->balance);
        if ($available < $remaining) {
            throw new InsufficientLeaveBalanceException('Insufficient annual leave balance.');
        }
        $transactions = [];
        foreach ($batches as $batch) {
            if ($remaining <= 0) {
                break;
            }
            $take = min((float) $batch->balance, $remaining);
            $batch->balance = (float) $batch->balance - $take;
            $batch->save();
            $transactions[] = LeaveTransaction::create([
                'employee_id' => $employee->id, 'leave_balance_id' => $batch->id,
                'leave_request_id' => $request->id, 'transaction_type' => LeaveTransactionType::Consumption->value,
                'amount' => -1 * $take, 'balance_after' => (float) $batch->balance,
                'reason' => 'Approved leave consumption', 'occurred_at' => $occurredAt,
                'metadata' => ['leave_request_id' => $request->id],
            ]);
            $remaining -= $take;
        }

        return $transactions;
    }

    public function availableBalance(Employee $employee, LeaveType $type, ?CarbonImmutable $asOf = null): LeaveBalanceData
    {
        $asOf ??= CarbonImmutable::now();
        $target = $this->consumesAnnualBalance($type) ? ($this->annualLeaveType() ?? $type) : $type;
        $driver = DB::getDriverName();
        // PostgreSQL sorts NULLS FIRST by default; batches with no expiry must
        // appear AFTER dated batches in available balance listing.
        if ($driver === 'sqlite') {
            $rows = LeaveBalance::where('employee_id', $employee->id)
                ->where('leave_type_id', $target->id)
                ->where('balance', '>', 0)
                ->orderByRaw('expires_at IS NULL, expires_at ASC')
                ->orderBy('id')
                ->get();
        } else {
            $rows = LeaveBalance::where('employee_id', $employee->id)
                ->where('leave_type_id', $target->id)
                ->where('balance', '>', 0)
                ->orderByRaw('expires_at ASC NULLS LAST')
                ->orderBy('id')
                ->get();
        }
        $available = 0.0;
        $batches = [];
        foreach ($rows as $row) {
            $rawExp = $row->getAttribute('expires_at');
            $exp = $rawExp !== null ? CarbonImmutable::parse($rawExp)->startOfDay() : null;
            if ($exp !== null && $exp->lessThan($asOf->startOfDay())) {
                continue;
            }
            $available += (float) $row->balance;
            $batches[] = ['id' => $row->id, 'period' => $row->period, 'balance' => (float) $row->balance, 'expires_at' => $exp?->toDateString()];
        }

        return new LeaveBalanceData($employee->id, $target->id, $available, $batches);
    }

    /**
     * Idempotent monthly accrual. Returns created accrual transactions.
     *
     * @return list<LeaveTransaction>
     */
    public function accrue(Employee $employee, CarbonImmutable $asOf, ?int $actorId = null): array
    {
        $eligibility = $this->resolveEligibility($employee, $asOf);
        if (! $eligibility->eligible) {
            throw new LeaveNotEligibleException($eligibility->reason);
        }
        $annual = $this->annualLeaveType();
        if ($annual === null) {
            throw new InvalidLeaveStateException('Annual leave type is not configured.');
        }
        $created = [];
        foreach ($this->dueAccrualDates($employee, $asOf) as $date) {
            $period = $date->toDateString();
            $balance = LeaveBalance::where('employee_id', $employee->id)
                ->where('leave_type_id', $annual->id)->where('period', $period)->first();
            if ($balance === null) {
                try {
                    $balance = LeaveBalance::create([
                        'employee_id' => $employee->id, 'leave_type_id' => $annual->id,
                        'period' => $period, 'balance' => 0,
                        'expires_at' => $this->expiryForAccrual($date)->toDateString(),
                    ]);
                } catch (UniqueConstraintViolationException) {
                    $balance = LeaveBalance::where('employee_id', $employee->id)
                        ->where('leave_type_id', $annual->id)->where('period', $period)->first();
                }
            }
            if ($balance === null) {
                continue;
            }
            $exists = LeaveTransaction::where('leave_balance_id', $balance->id)
                ->where('transaction_type', LeaveTransactionType::Accrual->value)
                ->whereDate('occurred_at', $period)->exists();
            if ($exists) {
                continue;
            }
            $balance->balance = (float) $balance->balance + self::MONTHLY_ACCRUAL_DAYS;
            $balance->save();
            $created[] = LeaveTransaction::create([
                'employee_id' => $employee->id, 'leave_balance_id' => $balance->id,
                'transaction_type' => LeaveTransactionType::Accrual->value,
                'amount' => self::MONTHLY_ACCRUAL_DAYS, 'balance_after' => (float) $balance->balance,
                'reason' => 'Monthly annual leave accrual', 'occurred_at' => $date,
                'metadata' => ['period' => $period, 'actor_id' => $actorId],
            ]);
        }

        return $created;
    }
}
