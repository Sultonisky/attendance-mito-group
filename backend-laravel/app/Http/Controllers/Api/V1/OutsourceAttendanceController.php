<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Outsource\InitializeOutsourceAttendanceSession;
use App\Actions\Outsource\OutsourceCheckIn;
use App\Actions\Outsource\OutsourceCheckOut;
use App\Actions\Outsource\ResolveOutsourceSession;
use App\Exceptions\Domain\OutsourceDeviceBusyException;
use App\Http\Requests\Outsource\CheckInRequest;
use App\Http\Requests\Outsource\CheckOutRequest;
use App\Http\Requests\Outsource\SessionInitRequest;
use App\Http\Resources\Outsource\OutsourceAttendanceResource;
use App\Http\Resources\Outsource\OutsourceAttendanceSessionResource;
use App\Models\AttendanceRecord;
use App\Models\AttendanceSession;
use App\Models\City;
use App\Models\Outsource;
use App\Models\OutsourceAttendanceSession;
use App\Models\OutsourceStoreAssignment;
use App\Models\WorkLocation;
use App\Actions\Audit\RecordAuditAction;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class OutsourceAttendanceController
{
    public function __construct(
        protected InitializeOutsourceAttendanceSession $initializeSession,
        protected ResolveOutsourceSession $resolveSession,
        protected OutsourceCheckIn $checkIn,
        protected OutsourceCheckOut $checkOut,
    ) {}

    public function cities(Request $request): JsonResponse
    {
        $cities = City::query()
            ->withoutGlobalScopes()
            ->where('status', 'active')
            ->orderBy('name')
            ->get(['id', 'name', 'code']);

        return response()->json([
            'success' => true,
            'data' => $cities,
        ]);
    }

    public function stores(Request $request): JsonResponse
    {
        $cityId = (int) $request->query('city_id');

        $query = WorkLocation::query()
            ->where('status', 'active')
            ->select('id', 'name', 'city_id', 'latitude', 'longitude');

        if ($cityId > 0) {
            $query->where('city_id', $cityId);
        }

        $stores = $query->orderBy('name')->get()->map(function (WorkLocation $store): array {
            return [
                'id' => $store->id,
                'name' => $store->name,
                'city_id' => $store->city_id,
                'latitude' => $store->latitude,
                'longitude' => $store->longitude,
                'radius_meters' => (float) config('attendance.outsource_geofence_radius_meters', 150),
            ];
        })->values();

        return response()->json([
            'success' => true,
            'data' => $stores,
        ]);
    }

    public function outsources(Request $request): JsonResponse
    {
        $storeId = (int) $request->query('store_id');

        $query = Outsource::query()
            ->where('status', 'active')
            ->select('id', 'name', 'outsource_code');

        if ($storeId > 0) {
            $query->whereHas('stores', function ($q) use ($storeId) {
                $q->where('work_locations.id', $storeId)
                    ->where('outsource_store_assignments.status', 'active');
            });
        }

        $outsources = $query->orderBy('name')->get();

        return response()->json([
            'success' => true,
            'data' => $outsources,
        ]);
    }

    public function initSession(SessionInitRequest $request): JsonResponse
    {
        try {
            $result = $this->initializeSession->execute(
                (int) $request->input('city_id'),
                (int) $request->input('store_id'),
                (int) $request->input('outsource_id'),
                (string) $request->input('device_fingerprint'),
                $request->userAgent(),
                $request->ip(),
            );
        } catch (OutsourceDeviceBusyException $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
                'code' => 'DEVICE_BUSY',
            ], 409);
        } catch (\InvalidArgumentException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Invalid selection.',
                'code' => 'INVALID_SELECTION',
            ], 422);
        }

        $session = $result['session'];

        return response()->json([
            'success' => true,
            'data' => [
                'session_token' => $result['session_token'],
                'expires_at' => $session->expires_at->toIso8601String(),
                'outsource' => [
                    'id' => $result['outsource']->id,
                    'name' => $result['outsource']->name,
                    'outsource_code' => $result['outsource']->outsource_code,
                ],
                'store' => [
                    'id' => $session->workLocation->id,
                    'name' => $session->workLocation->name,
                ],
            ],
        ], 201);
    }

    public function checkIn(CheckInRequest $request): JsonResponse
    {
        $rawToken = $this->extractToken($request);
        $resolution = $this->resolveSession->execute($rawToken);

        if (! $resolution['valid']) {
            return response()->json([
                'success' => false,
                'message' => $resolution['message'],
                'code' => $resolution['code'],
            ], 401);
        }

        $session = $resolution['session'];
        $outsource = $session->outsource;

        if ($outsource === null || ! $outsource->isAttendanceActive()) {
            return response()->json([
                'success' => false,
                'message' => 'Outsource is inactive.',
                'code' => 'INACTIVE_OUTSOURCE',
            ], 422);
        }

        $store = $session->workLocation;

        if ($store === null || $store->status !== 'active' || $store->trashed()) {
            return response()->json([
                'success' => false,
                'message' => 'Store is inactive.',
                'code' => 'INACTIVE_STORE',
            ], 422);
        }

        $assignment = OutsourceStoreAssignment::query()
            ->where('outsource_id', $outsource->id)
            ->where('store_id', $store->id)
            ->where('status', 'active')
            ->first();

        if ($assignment === null) {
            return response()->json([
                'success' => false,
                'message' => 'Assignment is no longer valid.',
                'code' => 'INVALID_ASSIGNMENT',
            ], 422);
        }

        $occurredAt = $this->resolveOccurredAt($request);
        $context = $request->validated();

        $result = $this->checkIn->execute($outsource, $session, $occurredAt, $context);

        if (! $result['success']) {
            return response()->json([
                'success' => false,
                'message' => $result['message'],
                'code' => $result['error'],
                'geofence' => $result['geofence'] ?? null,
            ], 422);
        }

        return (new OutsourceAttendanceResource([
            'id' => $result['record']->id,
            'status' => $result['record']->status,
            'attendance_date' => $result['record']->attendance_date,
            'check_in_at' => $result['session']->check_in_at,
            'check_out_at' => $result['session']->check_out_at,
            'duration_minutes' => $result['session']->duration_minutes,
        ]))->response()->setStatusCode(201);
    }

    public function checkOut(CheckOutRequest $request): JsonResponse
    {
        $rawToken = $this->extractToken($request);
        $resolution = $this->resolveSession->execute($rawToken);

        if (! $resolution['valid']) {
            return response()->json([
                'success' => false,
                'message' => $resolution['message'],
                'code' => $resolution['code'],
            ], 401);
        }

        $session = $resolution['session'];
        $outsource = $session->outsource;

        if ($outsource === null || ! $outsource->isAttendanceActive()) {
            return response()->json([
                'success' => false,
                'message' => 'Outsource is inactive.',
                'code' => 'INACTIVE_OUTSOURCE',
            ], 422);
        }

        $store = $session->workLocation;

        if ($store === null || $store->status !== 'active' || $store->trashed()) {
            return response()->json([
                'success' => false,
                'message' => 'Store is inactive.',
                'code' => 'INACTIVE_STORE',
            ], 422);
        }

        $assignment = OutsourceStoreAssignment::query()
            ->where('outsource_id', $outsource->id)
            ->where('store_id', $store->id)
            ->where('status', 'active')
            ->first();

        if ($assignment === null) {
            return response()->json([
                'success' => false,
                'message' => 'Assignment is no longer valid.',
                'code' => 'INVALID_ASSIGNMENT',
            ], 422);
        }

        $occurredAt = $this->resolveOccurredAt($request);
        $context = $request->validated();

        $result = $this->checkOut->execute($outsource, $session, $occurredAt, $context);

        if (! $result['success']) {
            return response()->json([
                'success' => false,
                'message' => $result['message'],
                'code' => $result['error'],
                'geofence' => $result['geofence'] ?? null,
            ], 422);
        }

        return (new OutsourceAttendanceResource([
            'id' => $result['record']->id,
            'status' => $result['record']->status,
            'attendance_date' => $result['record']->attendance_date,
            'check_in_at' => $result['session']->check_in_at,
            'check_out_at' => $result['session']->check_out_at,
            'duration_minutes' => $result['session']->duration_minutes,
        ]))->response();
    }

    /**
     * Void (soft-delete) an outsource attendance record — admin only.
     * Deletes the record, all its sessions, and all its events within a transaction.
     */
    public function voidRecord(AttendanceRecord $record, Request $request): JsonResponse
    {
        if ($record->outsource_id === null || $record->attendable_type !== 'outsource') {
            return response()->json(['success' => false, 'message' => 'Not an outsource attendance record.'], 422);
        }

        DB::transaction(function () use ($record, $request): void {
            $snapshot = [
                'attendance_date' => $record->attendance_date,
                'outsource_id'    => $record->outsource_id,
                'status'          => $record->status,
            ];

            // Delete sessions and events within a transaction.
            $sessionIds = $record->sessions()->pluck('id');
            if ($sessionIds->isNotEmpty()) {
                \App\Models\AttendanceEvent::whereIn('attendance_session_id', $sessionIds)->delete();
            }
            $record->sessions()->delete();
            $record->delete();

            app(RecordAuditAction::class)->execute(
                $request->user()?->getKey(),
                'outsource_attendance.voided',
                $record,
                $snapshot,
                null,
                $request,
            );
        });

        return response()->json(['success' => true], 200);
    }

    private function extractToken(Request $request): string
    {
        $header = $request->header('Authorization');

        if ($header === null || ! str_starts_with($header, 'Bearer ')) {
            throw new \InvalidArgumentException('Missing session token.');
        }

        $token = substr($header, 7);

        if ($token === '') {
            throw new \InvalidArgumentException('Missing session token.');
        }

        return $token;
    }

    private function resolveOccurredAt(Request $request): CarbonImmutable
    {
        $header = $request->header('X-Occurred-At');

        if ($header !== null) {
            try {
                return CarbonImmutable::createFromFormat(CarbonImmutable::ATOM, (string) $header);
            } catch (\Throwable) {
                // fall through to now()
            }
        }

        return CarbonImmutable::now();
    }
}
