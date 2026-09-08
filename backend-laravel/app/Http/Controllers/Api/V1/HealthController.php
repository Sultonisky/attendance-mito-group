<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\FastApiStatus;
use App\Http\Controllers\Controller;
use App\Services\Integration\FastApiService;
use Illuminate\Http\JsonResponse;

class HealthController extends Controller
{
    /**
     * Laravel API health check.
     */
    public function app(): JsonResponse
    {
        return response()->json([
            'status' => 'ok',
            'service' => 'laravel-api',
        ]);
    }

    /**
     * Laravel -> FastAPI communication health check.
     *
     * Returns a structured 503 when the internal AI service is not available
     * instead of leaking an exception/stack trace to the client.
     */
    public function ai(FastApiService $fastApi): JsonResponse
    {
        $result = $fastApi->health();

        if ($result['status'] !== FastApiStatus::Available) {
            return response()->json([
                'message' => 'AI service is not available.',
                'laravel' => 'ok',
                'ai_status' => $result['status']->value,
            ], 503);
        }

        return response()->json([
            'laravel' => 'ok',
            'ai' => $result['health'],
        ]);
    }
}
