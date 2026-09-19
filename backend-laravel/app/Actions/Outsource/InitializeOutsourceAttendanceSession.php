<?php

namespace App\Actions\Outsource;

use App\Actions\Audit\RecordAuditAction;
use App\Enums\OutsourceAttendanceSessionStatus;
use App\Exceptions\Domain\OutsourceDeviceBusyException;
use App\Models\Outsource;
use App\Models\OutsourceAttendanceSession;
use App\Models\OutsourceStoreAssignment;
use App\Models\WorkLocation;
use App\Services\Outsource\OutsourceDeviceLockService;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class InitializeOutsourceAttendanceSession
{
    public function __construct(
        protected RecordAuditAction $audit,
        protected OutsourceDeviceLockService $deviceLock,
    ) {}

    /**
     * @return array{
     *   session_token: string,
     *   session: OutsourceAttendanceSession,
     *   outsource: Outsource,
     *   store: WorkLocation
     * }
     */
    public function execute(
        int $cityId,
        int $storeId,
        int $outsourceId,
        string $deviceFingerprint,
        ?string $userAgent = null,
        ?string $ipAddress = null,
    ): array {
        $fingerprint = trim($deviceFingerprint);
        if ($fingerprint === '' || strlen($fingerprint) < 16) {
            throw new InvalidArgumentException('Invalid device fingerprint.');
        }

        $outsource = Outsource::where('id', $outsourceId)
            ->where('status', 'active')
            ->withoutGlobalScopes()
            ->first();

        if ($outsource === null || ! $outsource->isAttendanceActive()) {
            throw new InvalidArgumentException('Invalid selection.');
        }

        $store = WorkLocation::where('id', $storeId)
            ->where('city_id', $cityId)
            ->where('status', 'active')
            ->withoutGlobalScopes()
            ->first();

        if ($store === null) {
            throw new InvalidArgumentException('Invalid selection.');
        }

        $assignment = OutsourceStoreAssignment::query()
            ->where('outsource_id', $outsource->id)
            ->where('store_id', $store->id)
            ->where('status', 'active')
            ->first();

        if ($assignment === null) {
            throw new InvalidArgumentException('Invalid selection.');
        }

        $busyOutsourceId = $this->deviceLock->findOpenOutsourceIdForDevice($fingerprint, $outsource->id);
        if ($busyOutsourceId !== null) {
            throw new OutsourceDeviceBusyException(
                'Perangkat ini masih digunakan untuk absensi personel lain yang belum clock-out.'
            );
        }

        $rawToken = bin2hex(random_bytes(32));
        $tokenHash = hash('sha256', $rawToken);

        $session = DB::transaction(function () use ($outsource, $store, $tokenHash, $fingerprint, $userAgent, $ipAddress) {
            // One active browser session per outsource (invalidate old tabs/tokens).
            $this->deviceLock->revokeActiveSessionsForOutsource($outsource->id);

            // Drop stale active sessions on this device that never checked in.
            $this->deviceLock->revokeActiveSessionsForDevice($fingerprint, $outsource->id);

            return OutsourceAttendanceSession::create([
                'outsource_id' => $outsource->id,
                'work_location_id' => $store->id,
                'token_hash' => $tokenHash,
                'device_fingerprint' => $fingerprint,
                'ip_address' => $ipAddress,
                'user_agent' => $userAgent !== null ? mb_substr($userAgent, 0, 1000) : null,
                'status' => OutsourceAttendanceSessionStatus::Active->value,
                'expires_at' => now()->addHours(12),
                'last_used_at' => now(),
            ]);
        });

        $this->audit->execute(
            null,
            'outsource.session.init',
            $session,
            null,
            [
                'outsource_id' => $outsource->id,
                'store_id' => $store->id,
                'device_fingerprint' => $fingerprint,
            ],
            null,
            ['session_id' => $session->id]
        );

        return [
            'session_token' => $rawToken,
            'session' => $session->fresh('outsource', 'workLocation'),
            'outsource' => $outsource,
            'store' => $store,
        ];
    }
}
