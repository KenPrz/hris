<?php

declare(strict_types=1);

use App\Http\Controllers\Admin\Attendance\ManualPunchController;
use App\Http\Controllers\Admin\Employees\CreateEmployeeController;
use App\Http\Controllers\Admin\Employees\ProvisionUserController;
use App\Http\Controllers\Admin\Employees\RecordEmploymentController;
use App\Http\Controllers\Admin\Organizations\CreateController as CreateOrganizationController;
use App\Http\Controllers\Admin\Organizations\ListController as ListOrganizationsController;
use App\Http\Controllers\Admin\Organizations\UpdateController as UpdateOrganizationController;
use App\Http\Controllers\Admin\PayRules\CreateController as CreatePayRuleController;
use App\Http\Controllers\Admin\PayRules\DeleteController as DeletePayRuleController;
use App\Http\Controllers\Admin\PayRules\ListController as ListPayRulesController;
use App\Http\Controllers\Admin\PayRules\ShowController as ShowPayRuleController;
use App\Http\Controllers\Attendance\Adjustments\SubmitController as SubmitAdjustmentController;
use App\Http\Controllers\Attendance\ListEmployeeAttendanceController;
use App\Http\Controllers\Attendance\ListMyAttendanceController;
use App\Http\Controllers\Attendance\ListMySummaryController;
use App\Http\Controllers\Attendance\PunchController;
use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\Auth\LogoutController;
use App\Http\Controllers\Auth\MeController;
use App\Http\Controllers\Cutoff\CloseCutoffController;
use App\Http\Controllers\Cutoff\ExportCutoffController;
use App\Http\Controllers\Cutoff\ListCutoffsController;
use App\Http\Controllers\Cutoff\ReopenCutoffController;
use App\Http\Controllers\Employees\ListEmployeesController;
use App\Http\Controllers\Employees\ShowEmployeeController;
use App\Http\Controllers\Leave\GrantController as GrantLeaveController;
use App\Http\Controllers\Leave\ListEmployeeLeaveController;
use App\Http\Controllers\Leave\ListMyLeaveController;
use App\Http\Controllers\Leave\SubmitLeaveRequestController;
use App\Http\Controllers\Office\Holidays\CloneController as CloneHolidaysController;
use App\Http\Controllers\Office\Holidays\CreateController as CreateHolidayController;
use App\Http\Controllers\Office\Holidays\DeleteController as DeleteHolidayController;
use App\Http\Controllers\Office\Holidays\ListController as ListHolidaysController;
use App\Http\Controllers\Office\Holidays\UpdateController as UpdateHolidayController;
use App\Http\Controllers\Office\LeaveTypes\CreateController as CreateLeaveTypeController;
use App\Http\Controllers\Office\LeaveTypes\ListController as ListLeaveTypesController;
use App\Http\Controllers\Office\LeaveTypes\UpdateController as UpdateLeaveTypeController;
use App\Http\Controllers\Office\Schedules\CreateAssignmentController;
use App\Http\Controllers\Office\Schedules\CreateOverrideController;
use App\Http\Controllers\Office\Schedules\CreateTemplateController;
use App\Http\Controllers\Office\Schedules\DeleteAssignmentController;
use App\Http\Controllers\Office\Schedules\DeleteOverrideController;
use App\Http\Controllers\Office\Schedules\DeleteTemplateController;
use App\Http\Controllers\Office\Schedules\ListAssignmentsController;
use App\Http\Controllers\Office\Schedules\ListOverridesController;
use App\Http\Controllers\Office\Schedules\ListTemplatesController;
use App\Http\Controllers\Office\Schedules\ResolvedScheduleController;
use App\Http\Controllers\Office\Schedules\SetDefaultTemplateController;
use App\Http\Controllers\Office\Schedules\ShowTemplateController;
use App\Http\Controllers\Office\Schedules\UpdateOverrideController;
use App\Http\Controllers\Office\Schedules\UpdateTemplateController;
use App\Http\Controllers\Office\SetLeaveDayController;
use App\Http\Controllers\Overtime\SubmitOvertimeRequestController;
use App\Http\Controllers\Requests\ApproveController;
use App\Http\Controllers\Requests\CancelController;
use App\Http\Controllers\Requests\DownloadAttachmentController;
use App\Http\Controllers\Requests\ListMineController;
use App\Http\Controllers\Requests\OfficeApprovalsController;
use App\Http\Controllers\Requests\RejectController;
use App\Http\Controllers\Requests\ShowController;
use App\Http\Controllers\Requests\TeamApprovalsController;
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
        Route::get('/employees/{employee}/leave', ListEmployeeLeaveController::class);

        Route::get('/me/attendance', ListMyAttendanceController::class);
        Route::get('/me/attendance/summary', ListMySummaryController::class);
        Route::get('/me/leave', ListMyLeaveController::class);
        Route::post('/attendance/punch', PunchController::class)->middleware('idempotent');

        // Any employee may file for their own attendance — deliberately not admin-gated
        // and not behind idempotency middleware (a considered one-off submission, not a
        // retryable network event). Submission stays type-specific; the read/decision
        // surface below is the shared, type-agnostic requests spine.
        Route::post('/attendance/adjustments', SubmitAdjustmentController::class);

        // Same shape as the attendance-adjustment submission above: any employee may file
        // their own leave, not admin-gated, not behind idempotency middleware. The debit
        // amount is server-computed from the scheduled working days in range, never
        // client-supplied — see SubmitLeaveRequestController.
        Route::post('/leave/requests', SubmitLeaveRequestController::class);

        // Same shape as the leave/attendance-adjustment submissions above: any employee may
        // file their own overtime pre-authorization, not admin-gated. Single-hop — the
        // manager (or office HR) approves it once and the compute engine reads the cap.
        Route::post('/overtime/requests', SubmitOvertimeRequestController::class);

        // The two scope-filtered approval queues — a manager's direct reports and an HR
        // admin's office members — replace the old single combined
        // /attendance/adjustments/pending queue. Both are VIEWS over the same pending set
        // RequestAuthority::canDecide would accept; see ApprovalQueues. Not type-specific:
        // any request type appears here, not just attendance adjustments.
        Route::get('/team/approvals', TeamApprovalsController::class);
        Route::get('/office/approvals', OfficeApprovalsController::class);

        // The generic requests resource — list-mine, show, decisions, and the attachment
        // stream. Not type-specific: any request type (attendance adjustment today, leave
        // or overtime later) is served here, reached only via submission routes that stay
        // type-specific (e.g. POST /attendance/adjustments above).
        Route::get('/requests', ListMineController::class);

        // Transitions on the shared requests spine. Any authorized approver or the
        // requester themself may act — authority is enforced inside the actions
        // (RequestAuthority for approve/reject, requester-identity for cancel), not by a
        // route-level gate, so these stay in the plain auth:sanctum group.
        Route::post('/requests/{request}/approve', ApproveController::class);
        Route::post('/requests/{request}/reject', RejectController::class);
        Route::post('/requests/{request}/cancel', CancelController::class);

        // Show and the attachment stream share one visibility check (requester, or an
        // authorized approver) — see ShowController/DownloadAttachmentController. The
        // attachment route stays a private, app-mediated stream, never a public/object URL.
        Route::get('/requests/{request}', ShowController::class);
        Route::get('/requests/{request}/attachment', DownloadAttachmentController::class);

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

            // Pay rules are a company singleton, gated by each FormRequest's
            // authorize() (is_system_admin), not OfficeScope — there is no office to
            // scope by, and nothing to enumerate, so a non-admin gets the default 403
            // rather than the 404-not-403 treatment used elsewhere in this file.
            Route::get('/pay-rules', ListPayRulesController::class);
            Route::post('/pay-rules', CreatePayRuleController::class);

            // Versions are immutable — read and delete only, deliberately no
            // PATCH/PUT route. A correction is a new version, never an edit in place.
            Route::get('/pay-rules/{payRule}', ShowPayRuleController::class);
            Route::delete('/pay-rules/{payRule}', DeletePayRuleController::class);

            // The organization tree's root — global config, gated by each FormRequest's
            // authorize() (is_system_admin) exactly like pay-rules above, not OfficeScope:
            // there is no office to scope by yet (an organization is the parent an office
            // belongs to), so a non-admin gets the default 403 rather than 404-not-403.
            Route::get('/organizations', ListOrganizationsController::class);
            Route::post('/organizations', CreateOrganizationController::class);
            Route::patch('/organizations/{organization}', UpdateOrganizationController::class);
        });

        // Per-office config, gated by OfficeScope::administeredBy() inside each
        // controller — an out-of-scope office 404s exactly like a nonexistent one (the
        // same 404-not-403 discipline as the requests spine above).
        Route::prefix('office')->group(function (): void {
            Route::get('/holidays', ListHolidaysController::class);
            Route::post('/holidays', CreateHolidayController::class);
            Route::post('/holidays/clone', CloneHolidaysController::class);
            Route::patch('/holidays/{holiday}', UpdateHolidayController::class);
            Route::delete('/holidays/{holiday}', DeleteHolidayController::class);
            Route::get('/shift-templates', ListTemplatesController::class);
            Route::post('/shift-templates', CreateTemplateController::class);
            Route::get('/shift-templates/{template}', ShowTemplateController::class);
            Route::patch('/shift-templates/{template}', UpdateTemplateController::class);
            Route::delete('/shift-templates/{template}', DeleteTemplateController::class);
            Route::get('/schedule-assignments', ListAssignmentsController::class);
            Route::post('/schedule-assignments', CreateAssignmentController::class);
            Route::delete('/schedule-assignments/{assignment}', DeleteAssignmentController::class);
            Route::get('/schedule-overrides', ListOverridesController::class);
            Route::post('/schedule-overrides', CreateOverrideController::class);
            Route::patch('/schedule-overrides/{override}', UpdateOverrideController::class);
            Route::delete('/schedule-overrides/{override}', DeleteOverrideController::class);
            Route::patch('/default-template', SetDefaultTemplateController::class);
            Route::get('/schedule/resolved', ResolvedScheduleController::class);
            Route::patch('/leave-day', SetLeaveDayController::class);

            // Cutoff periods — list the office's stored periods plus its current open
            // window, close a semi-monthly boundary, and reopen a closed one. Gated by
            // OfficeScope only, same as every other route in this group; `cutoff.manage`
            // is a seeded permission (see RbacSeeder) but the enforced boundary here is
            // "administers this office", identical to leave-types/holidays.
            Route::get('/cutoffs', ListCutoffsController::class);
            Route::post('/cutoffs/close', CloseCutoffController::class);
            Route::post('/cutoffs/{period}/reopen', ReopenCutoffController::class);
            Route::get('/cutoffs/{period}/export', ExportCutoffController::class);

            // Leave-type config — no delete route; a type is retired via PATCH
            // is_active=false, never removed (M6b-a Task 4).
            Route::get('/leave-types', ListLeaveTypesController::class);
            Route::post('/leave-types', CreateLeaveTypeController::class);
            Route::patch('/leave-types/{leaveType}', UpdateLeaveTypeController::class);
        });

        // HR manual grants — scoped by OfficeScope::administers against the employee's
        // current office (not EmployeeScope, which would also let a manager grant to
        // their own direct reports; see GrantController). One credit row per grant.
        Route::prefix('leave')->group(function (): void {
            Route::post('/grants', GrantLeaveController::class);
        });
    });
});
