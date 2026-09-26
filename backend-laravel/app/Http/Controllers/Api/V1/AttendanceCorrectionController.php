<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Attendance\ApproveAttendanceCorrectionRequest;
use App\Actions\Attendance\CancelAttendanceCorrectionRequest;
use App\Actions\Attendance\CreateAttendanceCorrectionRequest;
use App\Actions\Attendance\RejectAttendanceCorrectionRequest;
use App\Http\Controllers\Controller;
use App\Http\Requests\Attendance\RejectAttendanceCorrectionRequestRequest;
use App\Http\Requests\Attendance\StoreAttendanceCorrectionRequest;
use App\Http\Resources\Attendance\AttendanceCorrectionRequestResource;
use App\Models\AttendanceCorrectionRequest;
use App\Models\Employee;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;

/**
 * Employee attendance correction requests (forgotten clock in/out).
 */
class AttendanceCorrectionController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $privileged = $this->isPrivileged($request);
        $employee = $this->employeeFor($request);

        if (! $privileged && $employee === null) {
            return response()->json(['success' => false, 'error' => 'Employee record not found.'], 404);
        }

        $query = AttendanceCorrectionRequest::query()
            ->with(['employee:id,full_name,employee_code']);

        if (! $privileged) {
            $query->where('employee_id', $employee->id);
        } elseif ($request->filled('employee_id')) {
            $query->where('employee_id', (int) $request->integer('employee_id'));
        }

        if ($request->filled('status')) {
            $query->where('status', (string) $request->string('status'));
        }

        if ($request->filled('from')) {
            $query->whereDate('attendance_date', '>=', (string) $request->string('from'));
        }

        if ($request->filled('to')) {
            $query->whereDate('attendance_date', '<=', (string) $request->string('to'));
        }

        $items = $query->orderByDesc('id')->paginate(
            min(100, max(1, (int) $request->integer('per_page', 20)))
        );

        return AttendanceCorrectionRequestResource::collection($items)->response();
    }

    public function store(
        StoreAttendanceCorrectionRequest $request,
        CreateAttendanceCorrectionRequest $action,
    ): JsonResponse {
        $employee = $this->employeeFor($request);
        if ($employee === null) {
            return response()->json(['success' => false, 'error' => 'Employee record not found.'], 404);
        }

        try {
            $correction = $action->execute($employee, $request->validated(), $request->user(), $request);
        } catch (InvalidArgumentException $e) {
            return response()->json(['success' => false, 'error' => $e->getMessage()], 422);
        }

        return (new AttendanceCorrectionRequestResource($correction))
            ->response()
            ->setStatusCode(201);
    }

    public function show(Request $request, AttendanceCorrectionRequest $correction): JsonResponse
    {
        $privileged = $this->isPrivileged($request);
        $employee = $this->employeeFor($request);

        if (! $privileged) {
            if ($employee === null || (int) $correction->employee_id !== (int) $employee->id) {
                return response()->json(['success' => false, 'error' => 'Correction request not found.'], 404);
            }
        }

        $correction->loadMissing(['employee:id,full_name,employee_code']);

        return (new AttendanceCorrectionRequestResource($correction))->response();
    }

    public function approve(
        Request $request,
        AttendanceCorrectionRequest $correction,
        ApproveAttendanceCorrectionRequest $action,
    ): JsonResponse {
        try {
            $correction = $action->execute($correction, $request->user(), $request);
        } catch (InvalidArgumentException $e) {
            return response()->json(['success' => false, 'error' => $e->getMessage()], 422);
        }

        return (new AttendanceCorrectionRequestResource($correction))->response();
    }

    public function reject(
        RejectAttendanceCorrectionRequestRequest $request,
        AttendanceCorrectionRequest $correction,
        RejectAttendanceCorrectionRequest $action,
    ): JsonResponse {
        try {
            $correction = $action->execute(
                $correction,
                $request->user(),
                $request->validated('reason'),
                $request,
            );
        } catch (InvalidArgumentException $e) {
            return response()->json(['success' => false, 'error' => $e->getMessage()], 422);
        }

        return (new AttendanceCorrectionRequestResource($correction))->response();
    }

    public function cancel(
        Request $request,
        AttendanceCorrectionRequest $correction,
        CancelAttendanceCorrectionRequest $action,
    ): JsonResponse {
        $employee = $this->employeeFor($request);

        // Admins with approve/reject may cancel any pending request.
        // Employees may cancel only their own.
        $canCancelAny = $request->user()->can('attendance.correction.approve')
            || $request->user()->can('attendance.correction.reject');

        if (! $canCancelAny) {
            if ($employee === null || (int) $correction->employee_id !== (int) $employee->id) {
                return response()->json(['success' => false, 'error' => 'Correction request not found.'], 404);
            }
        }

        try {
            $correction = $action->execute($correction, $request->user(), $request);
        } catch (InvalidArgumentException $e) {
            return response()->json(['success' => false, 'error' => $e->getMessage()], 422);
        }

        return (new AttendanceCorrectionRequestResource($correction))->response();
    }

    private function employeeFor(Request $request): ?Employee
    {
        return $request->user()->employee
            ?? Employee::where('email', $request->user()->email)->first();
    }

    private function isPrivileged(Request $request): bool
    {
        return $request->user()->can('attendance.correction.approve')
            || $request->user()->can('attendance.correction.reject');
    }
}
