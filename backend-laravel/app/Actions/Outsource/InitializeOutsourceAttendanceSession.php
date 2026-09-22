<?php

namespace App\Actions\Outsource;

use App\Actions\Audit\RecordAuditAction;
use App\Enums\OutsourceAttendanceSessionStatus;
use App\Exceptions\Domain\OutsourceDeviceBusyException;
use App\Models\Outsource;
use App\Models\OutsourceStoreAssignment;
use App\Models\WorkLocation;
use App\Services\Outsource\OutsourceDeviceLockService;
use App\Services\Outsource\Session\OutsourceSessionData;
use App\Services\Outsource\Session\OutsourceSessionStoreInterface;
use App\Services\Outsource\Session\OutsourceSessionStoreUnavailableException;
use Carbon\CarbonImmutable;
use InvalidArgumentException;

class InitializeOutsourceAttendanceSession
{
    public function __construct(
        protected RecordAuditAction $audit,
        protected OutsourceDeviceLockService $deviceLock,
        protected OutsourceSessionStoreInterface $sessions,
    ) {}

    /**
     * @return array{
     *   session: OutsourceSessionData,
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

        // One active browser session per outsource (invalidate old tabs).
        $this->deviceLock->revokeActiveSessionsForOutsource($outsource->id);

        // Drop stale active sessions on this device that never checked in.
        $this->deviceLock->revokeActiveSessionsForDevice($fingerprint, $outsource->id);

        $now = CarbonImmutable::now();
        $ttlHours = max(1, (int) config('outsource_session.ttl_hours', 12));
        $session = new OutsourceSessionData(
            id: bin2hex(random_bytes(32)),
            outsourceId: $outsource->id,
            storeId: $store->id,
            status: OutsourceAttendanceSessionStatus::Active->value,
            deviceFingerprint: $fingerprint,
            ipAddress: $ipAddress,
            userAgent: $userAgent !== null ? mb_substr($userAgent, 0, 1000) : null,
            createdAt: $now,
            expiresAt: $now->addHours($ttlHours),
            lastUsedAt: $now,
        );

        try {
            $this->sessions->put($session);
        } catch (OutsourceSessionStoreUnavailableException $e) {
            throw $e;
        } catch (\Throwable $e) {
            throw new OutsourceSessionStoreUnavailableException(
                'Outsource session store is temporarily unavailable.',
                previous: $e,
            );
        }

        $this->audit->execute(
            null,
            'outsource.session.init',
            $outsource,
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
            'session' => $session,
            'outsource' => $outsource,
            'store' => $store,
        ];
    }
}
