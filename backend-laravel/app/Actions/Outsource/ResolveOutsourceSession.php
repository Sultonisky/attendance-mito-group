<?php

namespace App\Actions\Outsource;

use App\Enums\OutsourceAttendanceSessionStatus;
use App\Models\OutsourceAttendanceSession;
use Illuminate\Support\Facades\Hash;

class ResolveOutsourceSession
{
    public function execute(string $rawToken): array
    {
        $tokenHash = hash('sha256', $rawToken);

        $session = OutsourceAttendanceSession::query()
            ->where('token_hash', $tokenHash)
            ->first();

        if ($session === null) {
            return [
                'valid' => false,
                'session' => null,
                'code' => 'INVALID_SESSION',
                'message' => 'Invalid session.',
            ];
        }

        if ($session->status !== OutsourceAttendanceSessionStatus::Active->value) {
            $code = match ($session->status) {
                OutsourceAttendanceSessionStatus::Completed->value => 'SESSION_COMPLETED',
                OutsourceAttendanceSessionStatus::Revoked->value => 'SESSION_REVOKED',
                OutsourceAttendanceSessionStatus::Expired->value => 'SESSION_EXPIRED',
                default => 'INVALID_SESSION',
            };

            return [
                'valid' => false,
                'session' => null,
                'code' => $code,
                'message' => 'Session is no longer valid.',
            ];
        }

        if ($session->expires_at->isPast()) {
            return [
                'valid' => false,
                'session' => null,
                'code' => 'SESSION_EXPIRED',
                'message' => 'Session expired.',
            ];
        }

        $session->update(['last_used_at' => now()]);

        return [
            'valid' => true,
            'session' => $session,
            'code' => null,
            'message' => null,
        ];
    }
}
