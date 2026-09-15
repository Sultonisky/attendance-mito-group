<?php

namespace App\Actions\Penalty;

use App\Domain\Penalty\Exceptions\InvalidPenaltyTransitionException;
use App\Enums\PenaltyStatus;
use App\Models\AuditLog;
use App\Models\PenaltyRecord;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class VoidPenalty
{
    public function execute(User $actor, PenaltyRecord $penalty, string $reason): PenaltyRecord
    {
        if ($penalty->status !== PenaltyStatus::Applied->value) {
            throw new InvalidPenaltyTransitionException(
                'Only penalties with status "applied" can be voided. Current status: '.$penalty->status.'.'
            );
        }

        $penalty = DB::transaction(function () use ($actor, $penalty, $reason) {
            $penalty->update([
                'status' => PenaltyStatus::Voided->value,
                'reason' => $reason,
            ]);

            AuditLog::create([
                'actor_id' => $actor->id,
                'action' => 'penalty.voided',
                'auditable_type' => PenaltyRecord::class,
                'auditable_id' => $penalty->id,
                'old_values' => [
                    'status' => PenaltyStatus::Applied->value,
                ],
                'new_values' => [
                    'status' => PenaltyStatus::Voided->value,
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
