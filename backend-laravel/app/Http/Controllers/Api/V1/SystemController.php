<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\System\InfrastructureHealthService;
use Illuminate\Http\JsonResponse;

/**
 * Super-admin system diagnostics.
 *
 * Authorization is enforced by `role:SUPER_ADMIN` on the route.
 */
class SystemController extends Controller
{
    public function health(InfrastructureHealthService $health): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => $health->snapshot(),
        ]);
    }
}
