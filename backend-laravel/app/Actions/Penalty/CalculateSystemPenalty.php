<?php

namespace App\Actions\Penalty;

use App\Domain\Penalty\DTOs\PenaltyViolationData;
use App\Domain\Penalty\Engines\PenaltyEngine;
use App\Enums\PenaltySource;
use App\Enums\PenaltyStatus;
use App\Models\AuditLog;
use App\Models\Employee;
use App\Models\PenaltyRecord;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

class CalculateSystemPenalty
{
    public function __construct(
        private PenaltyEngine $penaltyEngine,
    ) {}

    /**
     * @return list<PenaltyRecord>
     */
    public function execute(Employee $employee, CarbonImmutable $date): array
    {
        $violations = $this->penaltyEngine->evaluate($employee, $date);

        $results = [];
        foreach ($violations as $violation) {
            $idempotencyKey = $this->buildIdempotencyKey($employee, $date, $violation);

            $existing = PenaltyRecord::where('idempotency_key', $idempotencyKey)->first();
            if ($existing) {
                $results[] = $existing;

                continue;
            }

            $record = DB::transaction(function () use ($employee, $violation, $idempotencyKey) {
                $record = PenaltyRecord::create([
                    'employee_id' => $employee->id,
                    'penalty_rule_id' => $violation->rule->id,
                    'attendance_id' => $violation->attendanceId,
                    'source' => PenaltySource::System->value,
                    'violation_type' => $violation->violationType->value,
                    'violation_custom' => null,
                    'original_points' => $violation->points,
                    'adjusted_points' => null,
                    'final_points' => $violation->points,
                    'reason' => null,
                    'status' => PenaltyStatus::Applied->value,
                    'occurred_at' => $violation->date->startOfDay()->toDateTimeString(),
                    'idempotency_key' => $idempotencyKey,
                ]);

                AuditLog::create([
                    'actor_id' => null,
                    'action' => 'penalty.created',
                    'auditable_type' => PenaltyRecord::class,
                    'auditable_id' => $record->id,
                    'old_values' => [],
                    'new_values' => [
                        'employee_id' => $employee->id,
                        'penalty_rule_id' => $violation->rule->id,
                        'source' => PenaltySource::System->value,
                        'violation_type' => $violation->violationType->value,
                        'points' => $violation->points,
                        'status' => PenaltyStatus::Applied->value,
                    ],
                    'ip_address' => null,
                    'user_agent' => null,
                    'metadata' => ['source' => 'system'],
                ]);

                return $record;
            });

            $results[] = $record;
        }

        return $results;
    }

    private function buildIdempotencyKey(Employee $employee, CarbonImmutable $date, PenaltyViolationData $violation): string
    {
        return sprintf(
            'system:emp:%d:date:%s:rule:%d:type:%s',
            $employee->id,
            $date->toDateString(),
            $violation->rule->id,
            $violation->violationType->value
        );
    }
}
