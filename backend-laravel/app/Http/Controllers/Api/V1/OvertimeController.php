<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Overtime\ApproveOvertimeRequest;
use App\Actions\Overtime\CancelOvertimeRequest;
use App\Actions\Overtime\CreateOvertimeRequest;
use App\Actions\Overtime\RejectOvertimeRequest;
use App\Domain\Overtime\Engines\OvertimeEngine;
use App\Http\Controllers\Controller;
use App\Http\Requests\Overtime\RejectOvertimeRequestRequest;
use App\Http\Requests\Overtime\StoreOvertimeRequest;
use App\Http\Resources\Overtime\OvertimeRequestResource;
use App\Http\Resources\Overtime\OvertimeResource;
use App\Models\Employee;
use App\Models\OvertimeRecord;
use App\Models\OvertimeRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Employee overtime API. Thin controller: identity/ownership checks plus
 * delegation to Actions and OvertimeEngine. No business rules live here.
 */
class OvertimeController extends Controller
{
    public function __construct(
        protected OvertimeEngine $engine,
        protected CreateOvertimeRequest $createAction,
        protected ApproveOvertimeRequest $approveAction,
        protected RejectOvertimeRequest $rejectAction,
        protected CancelOvertimeRequest $cancelAction,
    ) {}

    /**
     * List overtime records for the authenticated employee.
     */
    public function index(Request $request): JsonResponse
    {
        $employee = $this->employeeFor($request);
        if ($employee === null) {
            return response()->json(['success' => false, 'error' => 'Employee record not found.'], 404);
        }

        $privileged = $request->user()->can('overtime.approve') || $request->user()->can('overtime.reject');
        $query = $privileged ? OvertimeRecord::query() : OvertimeRecord::where('employee_id', $employee->id);

        if ($privileged && $request->filled('employee_id')) {
            $query->where('employee_id', (int) $request->integer('employee_id'));
        }

        $records = $query->orderByDesc('date')->paginate(30);

        return OvertimeResource::collection($records)->response();
    }

    /**
     * Show a single overtime record.
     */
    public function show(Request $request, OvertimeRecord $overtime): JsonResponse
    {
        $employee = $this->employeeFor($request);
        if ($employee === null) {
            return response()->json(['success' => false, 'error' => 'Employee record not found.'], 404);
        }

        $privileged = $request->user()->can('overtime.approve') || $request->user()->can('overtime.reject');
        if (! $privileged && (int) $overtime->employee_id !== (int) $employee->id) {
            return response()->json(['success' => false, 'error' => 'Overtime record not found.'], 404);
        }

        return (new OvertimeResource($overtime))->response();
    }

    /**
     * Create an overtime request.
     */
    public function store(StoreOvertimeRequest $request): JsonResponse
    {
        $employee = $this->employeeFor($request);
        if ($employee === null) {
            return response()->json(['success' => false, 'error' => 'Employee record not found.'], 404);
        }

        $otRequest = $this->createAction->execute($employee, $request->validated(), $request->user(), $request);

        return (new OvertimeRequestResource($otRequest))->response()->setStatusCode(201);
    }

    /**
     * Show a single overtime request.
     */
    public function showRequest(Request $request, OvertimeRequest $overtimeRequest): JsonResponse
    {
        $employee = $this->employeeFor($request);
        if ($employee === null) {
            return response()->json(['success' => false, 'error' => 'Employee record not found.'], 404);
        }

        $privileged = $request->user()->can('overtime.approve') || $request->user()->can('overtime.reject');
        if (! $privileged && (int) $overtimeRequest->employee_id !== (int) $employee->id) {
            return response()->json(['success' => false, 'error' => 'Overtime request not found.'], 404);
        }

        $overtimeRequest->load('overtimeRecord');

        return (new OvertimeRequestResource($overtimeRequest))->response();
    }

    /**
     * List overtime requests for the authenticated employee.
     */
    public function requests(Request $request): JsonResponse
    {
        $employee = $this->employeeFor($request);
        if ($employee === null) {
            return response()->json(['success' => false, 'error' => 'Employee record not found.'], 404);
        }

        $privileged = $request->user()->can('overtime.approve') || $request->user()->can('overtime.reject');
        $query = $privileged ? OvertimeRequest::with('overtimeRecord') : OvertimeRequest::where('employee_id', $employee->id)->with('overtimeRecord');

        if ($privileged && $request->filled('employee_id')) {
            $query->where('employee_id', (int) $request->integer('employee_id'));
        }

        $requests = $query->orderByDesc('id')->paginate(30);

        return OvertimeRequestResource::collection($requests)->response();
    }

    /**
     * Approve an overtime request.
     */
    public function approve(Request $request, OvertimeRequest $overtimeRequest, ApproveOvertimeRequest $approve): JsonResponse
    {
        $approvedMinutes = $request->has('approved_minutes') ? (int) $request->input('approved_minutes') : null;
        $approved = $approve->execute($overtimeRequest, $request->user(), $approvedMinutes, $request);
        $approved->load('overtimeRecord');

        return (new OvertimeRequestResource($approved))->response();
    }

    /**
     * Reject an overtime request.
     */
    public function reject(RejectOvertimeRequestRequest $request, OvertimeRequest $overtimeRequest, RejectOvertimeRequest $reject): JsonResponse
    {
        $rejected = $reject->execute($overtimeRequest, $request->user(), $request->validated()['reason'] ?? null, $request);
        $rejected->load('overtimeRecord');

        return (new OvertimeRequestResource($rejected))->response();
    }

    /**
     * Cancel an overtime request.
     */
    public function cancel(Request $request, OvertimeRequest $overtimeRequest): JsonResponse
    {
        $employee = $this->employeeFor($request);
        $isOwner = $employee !== null && (int) $overtimeRequest->employee_id === (int) $employee->id;
        if (! $isOwner && ! $request->user()->can('overtime.cancel')) {
            return response()->json(['success' => false, 'error' => 'Overtime request not found.'], 404);
        }

        $cancelled = $this->cancelAction->execute($overtimeRequest, $request->user(), $isOwner, $request);
        $cancelled->load('overtimeRecord');

        return (new OvertimeRequestResource($cancelled))->response();
    }

    private function employeeFor(Request $request): ?Employee
    {
        $user = $request->user();
        if ($user === null) {
            return null;
        }

        return Employee::where('user_id', $user->getKey())->first()
            ?? ($user->employee()->first() ?? Employee::where('email', $user->email)->first());
    }
}
