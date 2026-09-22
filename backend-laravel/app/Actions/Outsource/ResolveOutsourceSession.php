<?php

namespace App\Actions\Outsource;

use App\Enums\OutsourceAttendanceSessionStatus;
use App\Services\Outsource\Session\OutsourceSessionStoreInterface;
use App\Services\Outsource\Session\OutsourceSessionStoreUnavailableException;

class ResolveOutsourceSession
{
    public function __construct(
        protected OutsourceSessionStoreInterface $sessions,
    ) {}

    /**
     * @return array{
     *   valid: bool,
     *   session: ?\App\Services\Outsource\Session\OutsourceSessionData,
     *   code: ?string,
     *   message: ?string
     * }
     */
    public function execute(string $sessionId): array
    {
        $sessionId = trim($sessionId);
        if ($sessionId === '') {
            return [
                'valid' => false,
                'session' => null,
                'code' => 'INVALID_SESSION',
                'message' => 'Invalid session.',
            ];
        }

        try {
            $session = $this->sessions->find($sessionId);
        } catch (OutsourceSessionStoreUnavailableException) {
            return [
                'valid' => false,
                'session' => null,
                'code' => 'SESSION_STORE_UNAVAILABLE',
                'message' => 'Session store is temporarily unavailable.',
            ];
        }

        if ($session === null) {
            return [
                'valid' => false,
                'session' => null,
                'code' => 'INVALID_SESSION',
                'message' => 'Invalid session.',
            ];
        }

        if ($session->expiresAt->isPast()) {
            $this->sessions->delete($sessionId);

            return [
                'valid' => false,
                'session' => null,
                'code' => 'SESSION_EXPIRED',
                'message' => 'Session expired.',
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

        $touched = $this->sessions->touch($sessionId);

        return [
            'valid' => true,
            'session' => $touched ?? $session,
            'code' => null,
            'message' => null,
        ];
    }
}
