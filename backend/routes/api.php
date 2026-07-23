<?php

declare(strict_types=1);

use App\Http\Controllers\Admin\Attendance\ManualPunchController;
use App\Http\Controllers\Admin\Employees\CreateEmployeeController;
use App\Http\Controllers\Admin\Employees\ProvisionUserController;
use App\Http\Controllers\Admin\Employees\RecordEmploymentController;
use App\Http\Controllers\Attendance\Adjustments\ApproveController as ApproveAdjustmentController;
use App\Http\Controllers\Attendance\Adjustments\CancelController as CancelAdjustmentController;
use App\Http\Controllers\Attendance\Adjustments\RejectController as RejectAdjustmentController;
use App\Http\Controllers\Attendance\Adjustments\SubmitController as SubmitAdjustmentController;
use App\Http\Controllers\Attendance\ListEmployeeAttendanceController;
use App\Http\Controllers\Attendance\ListMyAttendanceController;
use App\Http\Controllers\Attendance\PunchController;
use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\Auth\LogoutController;
use App\Http\Controllers\Auth\MeController;
use App\Http\Controllers\Employees\ListEmployeesController;
use App\Http\Controllers\Employees\ShowEmployeeController;
use App\Http\Controllers\System\HealthController;
use Illuminate\Support\Facades\Route;

/*
| One system action = one route = one single-action controller = one Action class.
| This file and app/Actions/ are the same list; an endpoint with no action, or an
| action with no endpoint, is a visible bug. See docs/04-backend-conventions.md.
*/

Route::prefix('v1')->group(function (): void {
    Route::get('/health', HealthController::class);

    Route::post('/login', LoginController::class)->middleware('throttle:login');

    Route::middleware('auth:sanctum')->group(function (): void {
        Route::post('/logout', LogoutController::class);
        Route::get('/me', MeController::class);

        Route::get('/employees', ListEmployeesController::class);
        Route::get('/employees/{employee}', ShowEmployeeController::class);
        Route::get('/employees/{employee}/attendance', ListEmployeeAttendanceController::class);

        Route::get('/me/attendance', ListMyAttendanceController::class);
        Route::post('/attendance/punch', PunchController::class)->middleware('idempotent');

        // Any employee may file for their own attendance — deliberately not admin-gated
        // and not behind idempotency middleware (a considered one-off submission, not a
        // retryable network event).
        Route::post('/attendance/adjustments', SubmitAdjustmentController::class);

        // Transitions on the shared requests spine. Any authorized approver or the
        // requester themself may act — authority is enforced inside the actions
        // (RequestAuthority for approve/reject, requester-identity for cancel), not by a
        // route-level gate, so these stay in the plain auth:sanctum group.
        Route::post('/attendance/adjustments/{request}/approve', ApproveAdjustmentController::class);
        Route::post('/attendance/adjustments/{request}/reject', RejectAdjustmentController::class);
        Route::post('/attendance/adjustments/{request}/cancel', CancelAdjustmentController::class);

        // System Admin owns onboarding in M2 — no self-serve employee creation. Each
        // FormRequest's authorize() is the boundary: a non-admin gets 403, not 404,
        // because "you may not create employees at all" is an actor check, not the
        // out-of-scope-subject case the 404-not-403 rule protects.
        Route::prefix('admin')->group(function (): void {
            Route::post('/employees', CreateEmployeeController::class);
            Route::post('/employees/{employee}/user', ProvisionUserController::class);
            Route::post('/employees/{employee}/employment', RecordEmploymentController::class);

            // Manual entry is deliberately not behind `idempotent` — HR entering a
            // correction is a considered one-off, not a retryable network event.
            Route::post('/attendance/punch', ManualPunchController::class);
        });
    });
});
