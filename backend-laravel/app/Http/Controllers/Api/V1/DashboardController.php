<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Dashboard\DashboardTrendRequest;
use App\Http\Resources\Dashboard\DashboardKpiResource;
use App\Http\Resources\Dashboard\DashboardStaffResource;
use App\Http\Resources\Dashboard\DashboardTrendPointResource;
use App\Services\Dashboard\DashboardKpiQuery;
use Illuminate\Http\JsonResponse;

class DashboardController extends Controller
{
    public function __construct(private DashboardKpiQuery $dashboardKpiQuery) {}

    public function kpis(): JsonResponse
    {
        $data = $this->dashboardKpiQuery->forToday(request()->user());

        return (new DashboardKpiResource($data))->response();
    }

    public function staffToday(): JsonResponse
    {
        $rows = $this->dashboardKpiQuery->staffToday(request()->user());

        return DashboardStaffResource::collection(collect($rows))
            ->additional(['success' => true])
            ->response();
    }

    public function attendanceTrend(DashboardTrendRequest $request): JsonResponse
    {
        $points = $this->dashboardKpiQuery->attendanceTrend(
            $request->user(),
            $request->validated('from'),
            $request->validated('to'),
        );

        return DashboardTrendPointResource::collection(collect($points))
            ->additional(['success' => true])
            ->response();
    }
}
