<?php

namespace App\Actions\Penalty;

use App\Domain\Penalty\DTOs\CreateManualPenaltyData;
use App\Enums\PenaltySource;
use App\Enums\PenaltyStatus;
use App\Enums\PenaltyViolationType;
use App\Models\AuditLog;
use App\Models\PenaltyRecord;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class CreateManualPenalty
{
    public function execute(User $actor, CreateManualPenaltyData $data): PenaltyRecord
    {
        $record = DB::transaction(function () use ($actor, $data) {
            $record = PenaltyRecord::create([
                'employee_id' => $data->employeeId,
                'penalty_rule_id' => $data->penaltyRuleId,
                'attendance_id' => $data->attendanceId,
                'source' => PenaltySource::Manual->value,
                'violation_type' => $data->violationType->value,
                'violation_custom' => $data->violationType === PenaltyViolationType::Other ? $data->violationCustom : null,
                'original_points' => $data->points,
                'adjusted_points' => null,
                'final_points' => $data->points,
                'reason' => $data->reason,
                'status' => PenaltyStatus::Applied->value,
                'occurred_at' => $data->occurredAt->toDateTimeString(),
            ]);

            AuditLog::create([
                'actor_id' => $actor->id,
                'action' => 'penalty.created',
                'auditable_type' => PenaltyRecord::class,
                'auditable_id' => $record->id,
                'old_values' => [],
                'new_values' => [
                    'employee_id' => $data->employeeId,
                    'penalty_rule_id' => $data->penaltyRuleId,
                    'source' => PenaltySource::Manual->value,
                    'violation_type' => $data->violationType->value,
                    'violation_custom' => $data->violationCustom,
                    'points' => $data->points,
                    'status' => PenaltyStatus::Applied->value,
                ],
                'ip_address' => request()?->ip(),
                'user_agent' => request()?->userAgent(),
                'metadata' => ['source' => 'manual'],
            ]);

            return $record;
        });

        return $record;
    }
}
