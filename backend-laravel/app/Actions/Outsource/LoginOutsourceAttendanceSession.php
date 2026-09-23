<?php

namespace App\Actions\Outsource;

use App\Exceptions\Domain\OutsourceDeviceBusyException;
use App\Models\Outsource;
use App\Models\OutsourceStoreAssignment;
use App\Models\WorkLocation;
use App\Services\Outsource\Session\OutsourceSessionStoreUnavailableException;
use Illuminate\Support\Facades\Hash;
use InvalidArgumentException;

/**
 * Authenticate outsource person (kode + password) and mint Redis session for the active cabang.
 */
class LoginOutsourceAttendanceSession
{
    public function __construct(
        protected InitializeOutsourceAttendanceSession $initializeSession,
        protected ResolveOutsourceOpenAttendance $resolveOpenAttendance,
    ) {}

    /**
     * @return array{
     *   session: \App\Services\Outsource\Session\OutsourceSessionData,
     *   outsource: Outsource,
     *   store: WorkLocation,
     *   payload: array<string, mixed>
     * }
     */
    public function execute(
        string $outsourceCode,
        string $password,
        string $deviceFingerprint,
        ?string $userAgent = null,
        ?string $ipAddress = null,
    ): array {
        $code = trim($outsourceCode);
        if ($code === '' || $password === '') {
            throw new InvalidArgumentException('Invalid credentials.');
        }

        $outsource = Outsource::query()
            ->withoutGlobalScopes()
            ->where('outsource_code', $code)
            ->where('status', 'active')
            ->first();

        if ($outsource === null || ! $outsource->isAttendanceActive()) {
            throw new InvalidArgumentException('Invalid credentials.');
        }

        if ($outsource->password === null || ! Hash::check($password, $outsource->password)) {
            throw new InvalidArgumentException('Invalid credentials.');
        }

        $assignment = OutsourceStoreAssignment::query()
            ->where('outsource_id', $outsource->id)
            ->where('status', 'active')
            ->whereNull('deleted_at')
            ->orderBy('id')
            ->first();

        if ($assignment === null) {
            throw new InvalidArgumentException('Outsource has no active cabang assignment.');
        }

        $store = WorkLocation::query()
            ->withoutGlobalScopes()
            ->where('id', $assignment->store_id)
            ->where('status', 'active')
            ->first();

        if ($store === null) {
            throw new InvalidArgumentException('Assigned cabang is inactive.');
        }

        $cityId = (int) ($store->city_id ?? 0);

        try {
            $result = $this->initializeSession->execute(
                $cityId,
                (int) $store->id,
                (int) $outsource->id,
                $deviceFingerprint,
                $userAgent,
                $ipAddress,
            );
        } catch (OutsourceDeviceBusyException|OutsourceSessionStoreUnavailableException $e) {
            throw $e;
        }

        $attendance = $this->resolveOpenAttendance->execute($outsource->id);
        $status = $attendance !== null ? 'ACTIVE' : 'READY';

        $payload = $this->resolveOpenAttendance->buildSessionPayload(
            $status,
            $result['session']->expiresAt->toIso8601String(),
            $outsource,
            $store,
            $attendance,
        );

        $payload['can_clock_in'] = $attendance === null;
        $payload['can_clock_out'] = $attendance !== null;

        return [
            'session' => $result['session'],
            'outsource' => $outsource,
            'store' => $store,
            'payload' => $payload,
        ];
    }
}
