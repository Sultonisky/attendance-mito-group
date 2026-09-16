<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Report\ReportQueryRequest;
use App\Http\Resources\Report\AttendanceReportResource;
use App\Http\Resources\Report\LeaveReportResource;
use App\Http\Resources\Report\OvertimeReportResource;
use App\Http\Resources\Report\PenaltyReportResource;
use App\Services\Report\AttendanceReportQuery;
use App\Services\Report\LeaveReportQuery;
use App\Services\Report\OvertimeReportQuery;
use App\Services\Report\PenaltyReportQuery;
use Illuminate\Http\JsonResponse;

class ReportController extends Controller
{
    public function __construct(
        private AttendanceReportQuery $attendanceReportQuery,
        private LeaveReportQuery $leaveReportQuery,
        private OvertimeReportQuery $overtimeReportQuery,
        private PenaltyReportQuery $penaltyReportQuery,
    ) {}

    public function attendance(ReportQueryRequest $request): JsonResponse
    {
        $filters = $request->validated();
        $filters['per_page'] = (int) ($filters['per_page'] ?? 25);
        $filters['sort'] = $filters['sort'] ?? null;
        $filters['direction'] = $filters['direction'] ?? null;
        $filters['employee_id'] = $filters['employee_id'] ?? null;

        $result = $this->attendanceReportQuery->paginate($request->user(), $filters);

        return response()->json([
            'success' => true,
            'data' => AttendanceReportResource::collection($result),
            'meta' => [
                'current_page' => $result->currentPage(),
                'per_page' => $result->perPage(),
                'total' => $result->total(),
                'last_page' => $result->lastPage(),
            ],
        ]);
    }

    public function leave(ReportQueryRequest $request): JsonResponse
    {
        $filters = $request->validated();
        $filters['per_page'] = (int) ($filters['per_page'] ?? 25);
        $filters['sort'] = $filters['sort'] ?? null;
        $filters['direction'] = $filters['direction'] ?? null;
        $filters['employee_id'] = $filters['employee_id'] ?? null;

        $result = $this->leaveReportQuery->paginate($request->user(), $filters);

        return response()->json([
            'success' => true,
            'data' => LeaveReportResource::collection($result),
            'meta' => [
                'current_page' => $result->currentPage(),
                'per_page' => $result->perPage(),
                'total' => $result->total(),
                'last_page' => $result->lastPage(),
            ],
        ]);
    }

    public function overtime(ReportQueryRequest $request): JsonResponse
    {
        $filters = $request->validated();
        $filters['per_page'] = (int) ($filters['per_page'] ?? 25);
        $filters['sort'] = $filters['sort'] ?? null;
        $filters['direction'] = $filters['direction'] ?? null;
        $filters['employee_id'] = $filters['employee_id'] ?? null;

        $result = $this->overtimeReportQuery->paginate($request->user(), $filters);

        return response()->json([
            'success' => true,
            'data' => OvertimeReportResource::collection($result),
            'meta' => [
                'current_page' => $result->currentPage(),
                'per_page' => $result->perPage(),
                'total' => $result->total(),
                'last_page' => $result->lastPage(),
            ],
        ]);
    }

    public function penalty(ReportQueryRequest $request): JsonResponse
    {
        $filters = $request->validated();
        $filters['per_page'] = (int) ($filters['per_page'] ?? 25);
        $filters['sort'] = $filters['sort'] ?? null;
        $filters['direction'] = $filters['direction'] ?? null;
        $filters['employee_id'] = $filters['employee_id'] ?? null;

        $result = $this->penaltyReportQuery->paginate($request->user(), $filters);

        return response()->json([
            'success' => true,
            'data' => PenaltyReportResource::collection($result),
            'meta' => [
                'current_page' => $result->currentPage(),
                'per_page' => $result->perPage(),
                'total' => $result->total(),
                'last_page' => $result->lastPage(),
            ],
        ]);
    }
}
