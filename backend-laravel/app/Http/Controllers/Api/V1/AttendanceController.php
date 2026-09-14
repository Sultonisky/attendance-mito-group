<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Attendance\CheckInEmployee;
use App\Actions\Attendance\CheckOutEmployee;
use App\Http\Requests\Attendance\CheckInRequest;
use App\Http\Requests\Attendance\CheckOutRequest;
use App\Http\Resources\Attendance\AttendanceResource;
use App\Models\AttendanceRecord;
use App\Models\Employee;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

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
        $employee = Employee::where('email', $request->user()->email)->first();

        if ($employee === null) {
            return response()->json([
                'success' => false,
                'error'   => 'Employee record not found for the authenticated user.',
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
            return response()->json([
                'success' => false,
                'data'    => [
                    'status'   => $result['status']->value,
                    'error'    => $result['error'],
                    'geofence' => $result['geofence'],
                    'policy'   => $result['policy'],
                ],
            ], 422);
        }

        return (new AttendanceResource([
            'id'              => $result['record']->id,
            'employee_id'     => $result['record']->employee_id,
            'attendance_date' => $result['record']->attendance_date,
            'status'          => $result['record']->status,
            'sessions'        => [$result['session']],
            'geofence'        => $result['geofence'],
            'policy'          => $result['policy'],
            'error'           => null,
            'created_at'      => $result['record']->created_at,
            'updated_at'      => $result['record']->updated_at,
        ]))->response()->setStatusCode(201);
    }

    /**
     * Check out the authenticated employee.
     */
    public function checkOut(CheckOutRequest $request): JsonResponse
    {
        $employee = Employee::where('email', $request->user()->email)->first();

        if ($employee === null) {
            return response()->json([
                'success' => false,
                'error'   => 'Employee record not found for the authenticated user.',
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
                'data'    => [
                    'status'   => $result['status']->value,
                    'error'    => $result['error'],
                    'geofence' => $result['geofence'],
                    'policy'   => $result['policy'],
                ],
            ], 422);
        }

        return (new AttendanceResource([
            'id'              => $result['record']->id,
            'employee_id'     => $result['record']->employee_id,
            'attendance_date' => $result['record']->attendance_date,
            'status'          => $result['record']->status,
            'sessions'        => [$result['session']],
            'geofence'        => $result['geofence'],
            'policy'          => $result['policy'],
            'error'           => null,
            'created_at'      => $result['record']->created_at,
            'updated_at'      => $result['record']->updated_at,
        ]))->response();
    }

    /**
     * List attendance records for the authenticated employee.
     */
    public function index(Request $request): JsonResponse
    {
        $employee = Employee::where('email', $request->user()->email)->first();

        if ($employee === null) {
            return response()->json([
                'success' => false,
                'error'   => 'Employee record not found for the authenticated user.',
            ], 404);
        }

        $records = AttendanceRecord::query()
            ->where('employee_id', $employee->id)
            ->with(['sessions'])
            ->orderByDesc('attendance_date')
            ->paginate(30);

        return AttendanceResource::collection($records)->response();
    }

    /**
     * Show a single attendance record for the authenticated employee.
     */
    public function show(Request $request, AttendanceRecord $attendance): JsonResponse
    {
        $employee = Employee::where('email', $request->user()->email)->first();

        if ($employee === null) {
            return response()->json([
                'success' => false,
                'error'   => 'Employee record not found for the authenticated user.',
            ], 404);
        }

        if ($attendance->employee_id !== $employee->id) {
            return response()->json([
                'success' => false,
                'error'   => 'Attendance record not found.',
            ], 404);
        }

        $attendance->load('sessions');

        return (new AttendanceResource([
            'id'              => $attendance->id,
            'employee_id'     => $attendance->employee_id,
            'attendance_date' => $attendance->attendance_date,
            'status'          => $attendance->status,
            'sessions'        => $attendance->sessions,
            'geofence'        => null,
            'policy'          => null,
            'error'           => null,
            'created_at'      => $attendance->created_at,
            'updated_at'      => $attendance->updated_at,
        ]))->response();
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
                return CarbonImmutable::createFromFormat(CarbonImmutable::ATOM, (string) $header);
            } catch (\Throwable) {
                // fall through to now()
            }
        }

        return CarbonImmutable::now();
    }
}
