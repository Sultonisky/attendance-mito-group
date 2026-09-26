<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Attendance\CheckInEmployee;
use App\Actions\Attendance\CheckOutEmployee;
use App\Actions\Attendance\CreateEmployeeAttendance;
use App\Actions\Attendance\UpdateEmployeeAttendance;
use App\Actions\Attendance\VoidEmployeeAttendance;
use App\Http\Requests\Attendance\CheckInRequest;
use App\Http\Requests\Attendance\CheckOutRequest;
use App\Http\Requests\Attendance\IndexAttendanceRequest;
use App\Http\Requests\Attendance\StoreEmployeeAttendanceRequest;
use App\Http\Requests\Attendance\UpdateEmployeeAttendanceRequest;
use App\Http\Resources\Attendance\AttendanceResource;
use App\Models\AttendanceRecord;
use App\Models\Employee;
use App\Support\AttendanceDateTime;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;

class AttendanceController
{
    public function __construct(
        protected CheckInEmployee $checkIn,
        protected CheckOutEmployee $checkOut,
    ) {}

    /**
     * Check in the authenticated employee.
     */
    public function checkIn(CheckInRequest $request, CheckInEmployee $checkIn): JsonResponse
    {
        $employee = $request->user()->employee
            ?? Employee::where('email', $request->user()->email)->first();

        if ($employee === null) {
            return response()->json([
                'success' => false,
                'error' => 'Employee record not found for the authenticated user.',
            ], 404);
        }

        $occurredAt = $this->resolveOccurredAt($request);
        $context = $request->validated();

        $faceImageRelPath = null;
        if ($request->hasFile('face_image')) {
            $faceImageRelPath = $request->file('face_image')->store('face-verify-temp');
        }
        $faceImagePath = $faceImageRelPath !== null ? Storage::path($faceImageRelPath) : null;

        try {
            $result = $checkIn->execute(
                $employee,
                $occurredAt,
                $context,
                $request->user()->id,
                $request,
                $faceImagePath
            );
        } finally {
            if ($faceImageRelPath !== null) {
                Storage::delete($faceImageRelPath);
            }
        }

        if ($result['error'] !== null) {
            // 409 for duplicate/concurrent check-in (already open session).
            $statusCode = ($result['conflict'] ?? false) ? 409 : 422;

            return response()->json([
                'success' => false,
                'data' => [
                    'status' => $result['status']->value,
                    'error' => $result['error'],
                    'geofence' => $result['geofence'],
                    'policy' => $result['policy'],
                ],
            ], $statusCode);
        }

        return (new AttendanceResource([
            'id' => $result['record']->id,
            'employee_id' => $result['record']->employee_id,
            'attendance_date' => $result['record']->attendance_date,
            'status' => $result['record']->status,
            'sessions' => [$result['session']],
            'geofence' => $result['geofence'],
            'policy' => $result['policy'],
            'error' => null,
            'created_at' => $result['record']->created_at,
            'updated_at' => $result['record']->updated_at,
        ]))->response()->setStatusCode(201);
    }

    /**
     * Check out the authenticated employee.
     */
    public function checkOut(CheckOutRequest $request): JsonResponse
    {
        $employee = $request->user()->employee
            ?? Employee::where('email', $request->user()->email)->first();

        if ($employee === null) {
            return response()->json([
                'success' => false,
                'error' => 'Employee record not found for the authenticated user.',
            ], 404);
        }

        $occurredAt = $this->resolveOccurredAt($request);
        $context = $request->validated();

        $faceImageRelPath = null;
        if ($request->hasFile('face_image')) {
            $faceImageRelPath = $request->file('face_image')->store('face-verify-temp');
        }
        $faceImagePath = $faceImageRelPath !== null ? Storage::path($faceImageRelPath) : null;

        try {
            $result = $this->checkOut->execute(
                $employee,
                $occurredAt,
                $context,
                $request->user()->id,
                $request,
                $faceImagePath
            );
        } finally {
            if ($faceImageRelPath !== null) {
                Storage::delete($faceImageRelPath);
            }
        }

        if ($result['error'] !== null) {
            return response()->json([
                'success' => false,
                'data' => [
                    'status' => $result['status']->value,
                    'error' => $result['error'],
                    'geofence' => $result['geofence'],
                    'policy' => $result['policy'],
                ],
            ], 422);
        }

        return (new AttendanceResource([
            'id' => $result['record']->id,
            'employee_id' => $result['record']->employee_id,
            'attendance_date' => $result['record']->attendance_date,
            'status' => $result['record']->status,
            'sessions' => [$result['session']],
            'geofence' => $result['geofence'],
            'policy' => $result['policy'],
            'error' => null,
            'created_at' => $result['record']->created_at,
            'updated_at' => $result['record']->updated_at,
        ]))->response();
    }

    /**
     * List attendance records for the authenticated employee.
     */
    public function index(IndexAttendanceRequest $request): JsonResponse
    {
        $employee = $request->user()->employee
            ?? Employee::where('email', $request->user()->email)->first();

        if ($employee === null) {
            return response()->json([
                'success' => false,
                'error' => 'Employee record not found for the authenticated user.',
            ], 404);
        }

        $validated = $request->validated();
        $perPage = (int) ($validated['per_page'] ?? 30);

        $records = AttendanceRecord::query()
            ->where('employee_id', $employee->id)
            ->with(['sessions'])
            ->when(
                isset($validated['from']),
                fn ($query) => $query->whereDate('attendance_date', '>=', $validated['from'])
            )
            ->when(
                isset($validated['to']),
                fn ($query) => $query->whereDate('attendance_date', '<=', $validated['to'])
            )
            ->when(
                isset($validated['status']),
                fn ($query) => $query->where('status', $validated['status'])
            )
            ->orderByDesc('attendance_date')
            ->paginate($perPage);

        return AttendanceResource::collection($records)->response();
    }

    /**
     * Show a single attendance record for the authenticated employee.
     */
    public function show(Request $request, AttendanceRecord $attendance): JsonResponse
    {
        $employee = $request->user()->employee
            ?? Employee::where('email', $request->user()->email)->first();

        if ($employee === null) {
            return response()->json([
                'success' => false,
                'error' => 'Employee record not found for the authenticated user.',
            ], 404);
        }

        if ($attendance->employee_id !== $employee->id) {
            return response()->json([
                'success' => false,
                'error' => 'Attendance record not found.',
            ], 404);
        }

        $attendance->load('sessions');

        return (new AttendanceResource([
            'id' => $attendance->id,
            'employee_id' => $attendance->employee_id,
            'attendance_date' => $attendance->attendance_date,
            'status' => $attendance->status,
            'sessions' => $attendance->sessions,
            'geofence' => null,
            'policy' => null,
            'error' => null,
            'created_at' => $attendance->created_at,
            'updated_at' => $attendance->updated_at,
        ]))->response();
    }

    /**
     * Admin manual create of an employee attendance record.
     */
    public function storeAdmin(
        StoreEmployeeAttendanceRequest $request,
        CreateEmployeeAttendance $action,
    ): JsonResponse {
        try {
            $record = $action->execute($request->validated(), $request->user(), $request);
        } catch (InvalidArgumentException $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
        }

        return response()->json([
            'success' => true,
            'data' => $this->adminAttendancePayload($record),
        ], 201);
    }

    /**
     * Admin manual update (clock in/out). Employee and date stay locked.
     */
    public function updateAdmin(
        UpdateEmployeeAttendanceRequest $request,
        AttendanceRecord $attendance,
        UpdateEmployeeAttendance $action,
    ): JsonResponse {
        try {
            $updated = $action->execute($attendance, $request->validated(), $request->user(), $request);
        } catch (InvalidArgumentException $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
        }

        return response()->json([
            'success' => true,
            'data' => $this->adminAttendancePayload($updated),
        ]);
    }

    /**
     * Admin void (delete) an employee attendance record.
     */
    public function voidAdmin(
        AttendanceRecord $attendance,
        Request $request,
        VoidEmployeeAttendance $action,
    ): JsonResponse {
        try {
            $action->execute($attendance, $request->user(), $request);
        } catch (InvalidArgumentException $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
        }

        return response()->json(['success' => true]);
    }

    /**
     * @return array<string, mixed>
     */
    private function adminAttendancePayload(AttendanceRecord $record): array
    {
        $session = $record->sessions()->orderBy('id')->first();

        return [
            'attendance_id' => $record->id,
            'employee_id' => $record->employee_id,
            'attendance_date' => optional($record->attendance_date)?->toDateString() ?? $record->attendance_date,
            'status' => $record->status,
            'check_in_at' => AttendanceDateTime::toApi($session?->check_in_at),
            'check_out_at' => AttendanceDateTime::toApi($session?->check_out_at),
            'duration_minutes' => $session?->duration_minutes,
        ];
    }

    /**
     * Resolve the event timestamp from the request.
     *
     * Production uses the current time. Tests may provide an ISO-8601 timestamp
     * via the X-Occurred-At header to simulate specific attendance scenarios
     * (e.g. cross-midnight shifts) without relying on the wall clock.
     */
    private function resolveOccurredAt(Request $request): CarbonImmutable
    {
        $header = $request->header('X-Occurred-At');

        if ($header !== null) {
            try {
                return CarbonImmutable::parse((string) $header);
            } catch (\Throwable) {
                // fall through to now()
            }
        }

        return CarbonImmutable::now();
    }
}
