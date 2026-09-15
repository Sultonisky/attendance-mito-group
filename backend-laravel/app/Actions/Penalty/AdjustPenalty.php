<?php

namespace App\Actions\Penalty;

use App\Domain\Penalty\Exceptions\InvalidPenaltyTransitionException;
use App\Enums\PenaltyStatus;
use App\Models\AuditLog;
use App\Models\PenaltyRecord;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class AdjustPenalty
{
    public function execute(User $actor, PenaltyRecord $penalty, float $newPoints, string $reason): PenaltyRecord
    {
        if ($penalty->status !== PenaltyStatus::Applied->value) {
            throw new InvalidPenaltyTransitionException(
                'Only penalties with status "applied" can be adjusted. Current status: '.$penalty->status.'.'
            );
        }

        $oldPoints = $penalty->final_points;

        $penalty = DB::transaction(function () use ($actor, $penalty, $newPoints, $reason, $oldPoints) {
            $penalty->update([
                'status' => PenaltyStatus::Adjusted->value,
                'adjusted_points' => $newPoints,
                'final_points' => $newPoints,
                'reason' => $reason,
            ]);

            AuditLog::create([
                'actor_id' => $actor->id,
                'action' => 'penalty.adjusted',
                'auditable_type' => PenaltyRecord::class,
                'auditable_id' => $penalty->id,
                'old_values' => [
                    'status' => PenaltyStatus::Applied->value,
                    'final_points' => $oldPoints,
                ],
                'new_values' => [
                    'status' => PenaltyStatus::Adjusted->value,
                    'final_points' => $newPoints,
                    'reason' => $reason,
                ],
                'ip_address' => request()?->ip(),
                'user_agent' => request()?->userAgent(),
                'metadata' => [],
            ]);

            return $penalty;
        });

        return $penalty->fresh();
    }
}
