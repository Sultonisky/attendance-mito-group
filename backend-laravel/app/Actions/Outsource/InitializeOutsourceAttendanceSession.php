<?php

namespace App\Actions\Outsource;

use App\Actions\Audit\RecordAuditAction;
use App\Domain\Attendance\DTOs\AttendanceOperationData;
use App\Domain\Attendance\Engines\AttendanceEngine;
use App\Domain\Attendance\Exceptions\AttendanceAlreadyCheckedInException;
use App\Domain\Attendance\Exceptions\AttendanceBlockedByPolicyException;
use App\Domain\Attendance\Exceptions\OutsideGeofenceException;
use App\Enums\AttendanceEventType;
use App\Enums\OutsourceAttendanceSessionStatus;
use App\Exceptions\Domain\InactiveSubjectException;
use App\Models\Outsource;
use App\Models\OutsourceStoreAssignment;
use App\Models\OutsourceAttendanceSession;
use App\Models\WorkLocation;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

class InitializeOutsourceAttendanceSession
{
    public function __construct(
        protected RecordAuditAction $audit,
    ) {}

    public function execute(int $cityId, int $storeId, int $outsourceId, ?string $userAgent = null, ?string $ipAddress = null): array
    {
        $outsource = Outsource::where('id', $outsourceId)
            ->where('status', 'active')
            ->withoutGlobalScopes()
            ->first();

        if ($outsource === null || ! $outsource->isAttendanceActive()) {
            throw new \InvalidArgumentException('Invalid selection.');
        }

        $store = WorkLocation::where('id', $storeId)
            ->where('city_id', $cityId)
            ->where('status', 'active')
            ->withoutGlobalScopes()
            ->first();

        if ($store === null) {
            throw new \InvalidArgumentException('Invalid selection.');
        }

        $assignment = OutsourceStoreAssignment::query()
            ->where('outsource_id', $outsource->id)
            ->where('store_id', $store->id)
            ->where('status', 'active')
            ->first();

        if ($assignment === null) {
            throw new \InvalidArgumentException('Invalid selection.');
        }

        $rawToken = bin2hex(random_bytes(32));
        $tokenHash = hash('sha256', $rawToken);

        $session = DB::transaction(function () use ($outsource, $store, $tokenHash) {
            return OutsourceAttendanceSession::create([
                'outsource_id' => $outsource->id,
                'work_location_id' => $store->id,
                'token_hash' => $tokenHash,
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
