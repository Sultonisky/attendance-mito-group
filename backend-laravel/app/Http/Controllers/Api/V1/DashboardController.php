<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Dashboard\DashboardSourceRequest;
use App\Http\Requests\Dashboard\DashboardTrendRequest;
use App\Http\Resources\Dashboard\DashboardKpiResource;
use App\Http\Resources\Dashboard\DashboardStaffResource;
use App\Http\Resources\Dashboard\DashboardTrendPointResource;
use App\Services\Dashboard\DashboardKpiQuery;
use Illuminate\Http\JsonResponse;

class DashboardController extends Controller
{
    public function __construct(private DashboardKpiQuery $dashboardKpiQuery) {}

    public function kpis(DashboardSourceRequest $request): JsonResponse
    {
        $source = $request->validated('source') ?? 'employee';

        $data = $this->dashboardKpiQuery->forToday($request->user(), $source);

        return (new DashboardKpiResource($data))->response();
    }

    public function staffToday(DashboardSourceRequest $request): JsonResponse
    {
        $source = $request->validated('source') ?? 'employee';

        $rows = $this->dashboardKpiQuery->staffToday($request->user(), $source);

        return DashboardStaffResource::collection(collect($rows))
            ->additional(['success' => true])
            ->response();
    }

    public function attendanceTrend(DashboardTrendRequest $request): JsonResponse
    {
        $source = $request->validated('source') ?? 'employee';

        $points = $this->dashboardKpiQuery->attendanceTrend(
            $request->user(),
            $request->validated('from'),
            $request->validated('to'),
            $source,
        );

        return DashboardTrendPointResource::collection(collect($points))
            ->additional(['success' => true])
            ->response();
    }
}
