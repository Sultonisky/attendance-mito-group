<?php

use App\Http\Controllers\Api\V1\AttendanceController;
use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\HealthController;
use App\Http\Controllers\Api\V1\PenaltyController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::get('/user', function (Request $request) {
    return $request->user();
})->middleware('auth:sanctum');

Route::prefix('v1')->group(function () {
    // Infrastructure health (Phase 2)
    Route::get('/health', [HealthController::class, 'app']);
    Route::get('/ai-health', [HealthController::class, 'ai']);

    // Authentication (first-party Sanctum SPA, session/cookie based)
    Route::post('/login', [AuthController::class, 'login']);

    Route::middleware('auth:sanctum')->group(function () {
        Route::post('/logout', [AuthController::class, 'logout']);
        Route::get('/auth/me', [AuthController::class, 'me']);

        // Minimal permission-protected endpoint used to prove RBAC until
        // real business modules are implemented in later phases.
        Route::get('/rbac/demo', function (Request $request) {
            return response()->json([
                'success' => true,
                'data' => [
                    'message' => 'Permission check passed.',
                    'user' => $request->user()->email,
                ],
            ]);
        })->middleware('can:dashboard.view');

        // Attendance (Phase 7)
        Route::post('/attendance/check-in', [AttendanceController::class, 'checkIn']);
        Route::post('/attendance/check-out', [AttendanceController::class, 'checkOut']);

        // Penalty (Phase 11)
        Route::prefix('penalties')->middleware('auth:sanctum')->group(function () {
            Route::get('/', [PenaltyController::class, 'index'])->middleware('can:penalty.view');
            Route::get('/{penalty}', [PenaltyController::class, 'show'])->middleware('can:penalty.view');
            Route::post('/', [PenaltyController::class, 'store'])->middleware('can:penalty.create');
            Route::post('/{penalty}/adjust', [PenaltyController::class, 'adjust'])->middleware('can:penalty.adjust');
            Route::post('/{penalty}/void', [PenaltyController::class, 'void'])->middleware('can:penalty.void');
        });
    });
});
