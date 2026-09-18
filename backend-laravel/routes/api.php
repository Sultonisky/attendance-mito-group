<?php

use App\Http\Controllers\Api\V1\AttendanceController;
use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\DashboardController;
use App\Http\Controllers\Api\V1\FaceVerificationController;
use App\Http\Controllers\Api\V1\HealthController;
use App\Http\Controllers\Api\V1\LeaveController;
use App\Http\Controllers\Api\V1\MonthlyRecapController;
use App\Http\Controllers\Api\V1\OvertimeController;
use App\Http\Controllers\Api\V1\OutsourceAttendanceController;
use App\Http\Controllers\Api\V1\PenaltyController;
use App\Http\Controllers\Api\V1\ReportController;
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

    // Outsource public attendance (OUTSOURCE-2C)
    Route::prefix('outsource')->group(function () {
        Route::get('/cities', [OutsourceAttendanceController::class, 'cities'])
            ->middleware('throttle:60,1')
            ->name('outsource.cities');

        Route::get('/stores', [OutsourceAttendanceController::class, 'stores'])
            ->middleware('throttle:60,1')
            ->name('outsource.stores');

        Route::get('/outsources', [OutsourceAttendanceController::class, 'outsources'])
            ->middleware('throttle:60,1')
            ->name('outsource.outsources');

        Route::post('/session/init', [OutsourceAttendanceController::class, 'initSession'])
            ->middleware('throttle:10,5')
            ->name('outsource.session.init');

        Route::post('/attendance/check-in', [OutsourceAttendanceController::class, 'checkIn'])
            ->middleware('throttle:20,1')
            ->name('outsource.attendance.check-in');

        Route::post('/attendance/check-out', [OutsourceAttendanceController::class, 'checkOut'])
            ->middleware('throttle:20,1')
            ->name('outsource.attendance.check-out');
    });

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

        // Dashboard (Phase 13.2)
        Route::get('/dashboard/kpis', [DashboardController::class, 'kpis'])
            ->middleware('can:dashboard.view')
            ->name('dashboard.kpis');
        Route::get('/dashboard/staff-today', [DashboardController::class, 'staffToday'])
            ->middleware('can:dashboard.view')
            ->name('dashboard.staff-today');
        Route::get('/dashboard/attendance-trend', [DashboardController::class, 'attendanceTrend'])
            ->middleware('can:dashboard.view')
            ->name('dashboard.attendance-trend');

        // Penalty (Phase 11)
        Route::prefix('penalties')->middleware('auth:sanctum')->group(function () {
            Route::get('/', [PenaltyController::class, 'index'])->middleware('can:penalty.view');
            Route::get('/{penalty}', [PenaltyController::class, 'show'])->middleware('can:penalty.view');
            Route::post('/', [PenaltyController::class, 'store'])->middleware('can:penalty.create');
            Route::post('/{penalty}/adjust', [PenaltyController::class, 'adjust'])->middleware('can:penalty.adjust');
            Route::post('/{penalty}/void', [PenaltyController::class, 'void'])->middleware('can:penalty.void');
        });

        // Attendance (Phase 7)
        Route::prefix('attendance')->group(function () {
            Route::post('/check-in', [AttendanceController::class, 'checkIn'])
                ->name('attendance.check-in');
            Route::post('/check-out', [AttendanceController::class, 'checkOut'])
                ->name('attendance.check-out');
            Route::get('/', [AttendanceController::class, 'index'])
                ->name('attendance.index')
                ->middleware('can:attendance.view');
            Route::get('/{attendance}', [AttendanceController::class, 'show'])
                ->name('attendance.show')
                ->middleware('can:attendance.view');
        });

        // Face AI/CV verification (Phase 8)
        // FastAPI returns AI facts; Laravel makes the final business decision.
        Route::prefix('face')->group(function () {
            Route::post('/enroll', [FaceVerificationController::class, 'enroll'])
                ->name('face.enroll')
                ->middleware('permission:employees.manage-faces');
            Route::post('/verify', [FaceVerificationController::class, 'verify'])
                ->name('face.verify')
                ->middleware('permission:face.verify');
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

        // Monthly Recap (Phase 12)
        Route::prefix('monthly-recaps')->name('monthly-recap.')->group(function () {
            Route::get('/', [MonthlyRecapController::class, 'index'])->middleware('can:monthly_recap.view')->name('index');
            Route::post('/generate', [MonthlyRecapController::class, 'generate'])->middleware('can:monthly_recap.generate')->name('generate');
            Route::get('/{monthlyRecap}', [MonthlyRecapController::class, 'show'])->middleware('can:monthly_recap.view')->name('show');
            Route::post('/{monthlyRecap}/review', [MonthlyRecapController::class, 'review'])->middleware('can:monthly_recap.review')->name('review');
            Route::post('/{monthlyRecap}/finalize', [MonthlyRecapController::class, 'finalize'])->middleware('can:monthly_recap.finalize')->name('finalize');
            Route::post('/{monthlyRecap}/export', [MonthlyRecapController::class, 'export'])->middleware('can:monthly_recap.export')->name('export');
            Route::post('/{monthlyRecap}/reopen', [MonthlyRecapController::class, 'reopen'])->middleware('can:monthly_recap.finalize')->name('reopen');
        });

        // Reports (Phase 13.1)
        Route::prefix('reports')->name('reports.')->group(function () {
            Route::get('/attendance', [ReportController::class, 'attendance'])->middleware('can:attendance.view')->name('attendance');
            Route::get('/outsource-attendance', [ReportController::class, 'outsourceAttendance'])->middleware('can:outsource_attendance.view')->name('outsource-attendance');
            Route::get('/leave', [ReportController::class, 'leave'])->middleware('can:leave.view')->name('leave');
            Route::get('/overtime', [ReportController::class, 'overtime'])->middleware('can:overtime.view')->name('overtime');
            Route::get('/penalties', [ReportController::class, 'penalty'])->middleware('can:penalty.view')->name('penalty');
        });
    });
});
