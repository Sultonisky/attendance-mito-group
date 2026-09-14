<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Leave\ApproveLeaveRequest;
use App\Actions\Leave\CancelLeaveRequest;
use App\Actions\Leave\CreateLeaveRequest;
use App\Actions\Leave\RejectLeaveRequest;
use App\Domain\Leave\Engines\LeaveEngine;
use App\Http\Controllers\Controller;
use App\Http\Requests\Leave\RejectLeaveRequestRequest;
use App\Http\Requests\Leave\StoreLeaveRequest;
use App\Http\Resources\Leave\LeaveBalanceResource;
use App\Http\Resources\Leave\LeaveRequestResource;
use App\Http\Resources\Leave\LeaveTypeResource;
use App\Models\Employee;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Employee leave API. Thin controller: identity/ownership checks plus
 * delegation to Actions and LeaveEngine. No business rules live here.
 */
class LeaveController extends Controller
{
    public function types(): JsonResponse
    {
        $types = LeaveType::where('status', 'active')->orderBy('code')->get();

        return response()->json(['success' => true, 'data' => LeaveTypeResource::collection($types)]);
    }

    public function balance(Request $request, LeaveEngine $engine): JsonResponse
    {
        $employee = $this->employeeFor($request);
        if ($employee === null) {
            return response()->json(['success' => false, 'error' => 'Employee record not found.'], 404);
        }
        $annual = $engine->annualLeaveType();
        if ($annual === null) {
            return response()->json(['success' => false, 'error' => 'Annual leave type is not configured.'], 422);
        }
        $eligibility = $engine->resolveEligibility($employee);
        $balance = $engine->availableBalance($employee, $annual);

        return (new LeaveBalanceResource([
            'employee_id' => $employee->id, 'leave_type_id' => $annual->id,
            'leave_type_code' => $annual->code, 'eligible' => $eligibility->eligible,
            'eligibility_date' => $eligibility->eligibilityDate?->toDateString(),
            'available' => $balance->available, 'batches' => $balance->batches,
        ]))->response();
    }

    public function index(Request $request): JsonResponse
    {
        $employee = $this->employeeFor($request);
        if ($employee === null) {
            return response()->json(['success' => false, 'error' => 'Employee record not found.'], 404);
        }
        $privileged = $request->user()->can('leave.approve') || $request->user()->can('leave.reject');
        $query = $privileged ? LeaveRequest::with('leaveType') : LeaveRequest::where('employee_id', $employee->id)->with('leaveType');
        if ($privileged && $request->filled('employee_id')) {
            $query->where('employee_id', (int) $request->integer('employee_id'));
        }
        $requests = $query->orderByDesc('id')->paginate(30);

        return LeaveRequestResource::collection($requests)->response();
    }

    public function show(Request $request, LeaveRequest $leave): JsonResponse
    {
        $employee = $this->employeeFor($request);
        if ($employee === null) {
            return response()->json(['success' => false, 'error' => 'Employee record not found.'], 404);
        }
        $privileged = $request->user()->can('leave.approve') || $request->user()->can('leave.reject');
        if (! $privileged && (int) $leave->employee_id !== (int) $employee->id) {
            return response()->json(['success' => false, 'error' => 'Leave request not found.'], 404);
        }
        $leave->load('leaveType');

        return (new LeaveRequestResource($leave))->response();
    }

    public function store(StoreLeaveRequest $request, CreateLeaveRequest $action): JsonResponse
    {
        $employee = $this->employeeFor($request);
        if ($employee === null) {
            return response()->json(['success' => false, 'error' => 'Employee record not found.'], 404);
        }
        $leave = $action->execute($employee, $request->validated(), $request->user(), $request);
        $leave->load('leaveType');

        return (new LeaveRequestResource($leave))->response()->setStatusCode(201);
    }

    public function approve(Request $request, LeaveRequest $leave, ApproveLeaveRequest $action): JsonResponse
    {
        $approved = $action->execute($leave, $request->user(), $request);
        $approved->load('leaveType');

        return (new LeaveRequestResource($approved))->response();
    }

    public function reject(RejectLeaveRequestRequest $request, LeaveRequest $leave, RejectLeaveRequest $action): JsonResponse
    {
        $rejected = $action->execute($leave, $request->user(), $request->validated()['reason'] ?? null, $request);
        $rejected->load('leaveType');

        return (new LeaveRequestResource($rejected))->response();
    }

    public function cancel(Request $request, LeaveRequest $leave, CancelLeaveRequest $action): JsonResponse
    {
        $employee = $this->employeeFor($request);
        $isOwner = $employee !== null && (int) $leave->employee_id === (int) $employee->id;
        if (! $isOwner && ! $request->user()->can('leave.cancel')) {
            return response()->json(['success' => false, 'error' => 'Leave request not found.'], 404);
        }
        $cancelled = $action->execute($leave, $request->user(), $request, $isOwner);
        $cancelled->load('leaveType');

        return (new LeaveRequestResource($cancelled))->response();
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
