<?php

use App\Http\Controllers\Api\V1\AttendanceController;
use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\FaceVerificationController;
use App\Http\Controllers\Api\V1\HealthController;
use App\Http\Controllers\Api\V1\LeaveController;
use App\Http\Controllers\Api\V1\OvertimeController;
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
        Route::prefix('attendance')->group(function () {
            Route::post('/check-in', [AttendanceController::class, 'checkIn'])
                ->name('attendance.check-in');
            Route::post('/check-out', [AttendanceController::class, 'checkOut'])
                ->name('attendance.check-out');
            Route::get('/', [AttendanceController::class, 'index'])
                ->name('attendance.index');
            Route::get('/{attendance}', [AttendanceController::class, 'show'])
                ->name('attendance.show');
        });

        // Face AI/CV verification (Phase 8)
        // FastAPI returns AI facts; Laravel makes the final business decision.
        Route::prefix('face')->group(function () {
            Route::post('/enroll', [FaceVerificationController::class, 'enroll'])
                ->name('face.enroll')
                ->middleware('permission:employees.manage-faces');
            Route::post('/verify', [FaceVerificationController::class, 'verify'])
                ->name('face.verify');
        });

        // Leave (Phase 9): Laravel owns eligibility, accrual, expiry, FIFO,
        // lifecycle, and balance. Vue consumes these endpoints only.
        Route::prefix('leave')->group(function () {
            Route::get('/types', [LeaveController::class, 'types'])->name('leave.types')->middleware('can:leave.view');
            Route::get('/balance', [LeaveController::class, 'balance'])->name('leave.balance')->middleware('can:leave.view');
            Route::get('/requests', [LeaveController::class, 'index'])->name('leave.requests.index')->middleware('can:leave.view');
            Route::post('/requests', [LeaveController::class, 'store'])->name('leave.requests.store')->middleware('can:leave.create');
            Route::get('/requests/{leave}', [LeaveController::class, 'show'])->name('leave.requests.show')->middleware('can:leave.view');
            Route::post('/requests/{leave}/approve', [LeaveController::class, 'approve'])->name('leave.requests.approve')->middleware('can:leave.approve');
            Route::post('/requests/{leave}/reject', [LeaveController::class, 'reject'])->name('leave.requests.reject')->middleware('can:leave.reject');
            Route::post('/requests/{leave}/cancel', [LeaveController::class, 'cancel'])->name('leave.requests.cancel')->middleware('can:leave.cancel,leave');
        });

        // Overtime (Phase 10): detected potential overtime, requests, and approval.
        Route::prefix('overtime')->group(function () {
            Route::get('/', [OvertimeController::class, 'index'])->name('overtime.index')->middleware('can:overtime.view');
            Route::get('/{overtime}', [OvertimeController::class, 'show'])->name('overtime.show')->middleware('can:overtime.view');
            Route::post('/requests', [OvertimeController::class, 'store'])->name('overtime.requests.store')->middleware('can:overtime.create');
            Route::get('/requests', [OvertimeController::class, 'requests'])->name('overtime.requests.index')->middleware('can:overtime.view');
            Route::get('/requests/{overtimeRequest}', [OvertimeController::class, 'showRequest'])->name('overtime.requests.show')->middleware('can:overtime.view');
            Route::post('/requests/{overtimeRequest}/approve', [OvertimeController::class, 'approve'])->name('overtime.requests.approve')->middleware('can:overtime.approve');
            Route::post('/requests/{overtimeRequest}/reject', [OvertimeController::class, 'reject'])->name('overtime.requests.reject')->middleware('can:overtime.reject');
            Route::post('/requests/{overtimeRequest}/cancel', [OvertimeController::class, 'cancel'])->name('overtime.requests.cancel')->middleware('can:overtime.cancel,overtime');
        });
    });
});
