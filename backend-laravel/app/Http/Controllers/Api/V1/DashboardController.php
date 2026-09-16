<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\Dashboard\DashboardKpiResource;
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
}
