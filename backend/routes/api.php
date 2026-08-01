<?php

declare(strict_types=1);

use App\Http\Controllers\Admin\Attendance\ManualPunchController;
use App\Http\Controllers\Admin\Departments\ArchiveController as ArchiveDepartmentController;
use App\Http\Controllers\Admin\Departments\CreateController as CreateDepartmentController;
use App\Http\Controllers\Admin\Departments\ListController as ListDepartmentsController;
use App\Http\Controllers\Admin\Departments\UnarchiveController as UnarchiveDepartmentController;
use App\Http\Controllers\Admin\Departments\UpdateController as UpdateDepartmentController;
use App\Http\Controllers\Admin\Documents\Categories\CreateController as CreateDocumentCategoryController;
use App\Http\Controllers\Admin\Documents\Categories\DeleteController as DeleteDocumentCategoryController;
use App\Http\Controllers\Admin\Documents\Categories\ListController as ListDocumentCategoriesController;
use App\Http\Controllers\Admin\Documents\Categories\UpdateController as UpdateDocumentCategoryController;
use App\Http\Controllers\Admin\Employees\CreateEmployeeController;
use App\Http\Controllers\Admin\Employees\ListController as ListEmployeesAdminController;
use App\Http\Controllers\Admin\Employees\ProvisionUserController;
use App\Http\Controllers\Admin\Employees\RecordEmploymentController;
use App\Http\Controllers\Admin\Employees\SetHrAdminOfficesController;
use App\Http\Controllers\Admin\Employees\ShowController as ShowEmployeeAdminController;
use App\Http\Controllers\Admin\Employees\UpdateEmployeeController;
use App\Http\Controllers\Admin\ListActivityController;
use App\Http\Controllers\Admin\Offices\ArchiveController as ArchiveOfficeController;
use App\Http\Controllers\Admin\Offices\CreateController as CreateOfficeController;
use App\Http\Controllers\Admin\Offices\ListController as ListOfficesController;
use App\Http\Controllers\Admin\Offices\UnarchiveController as UnarchiveOfficeController;
use App\Http\Controllers\Admin\Offices\UpdateController as UpdateOfficeController;
use App\Http\Controllers\Admin\Organizations\CreateController as CreateOrganizationController;
use App\Http\Controllers\Admin\Organizations\ListController as ListOrganizationsController;
use App\Http\Controllers\Admin\Organizations\UpdateController as UpdateOrganizationController;
use App\Http\Controllers\Admin\PayRules\CreateController as CreatePayRuleController;
use App\Http\Controllers\Admin\PayRules\DeleteController as DeletePayRuleController;
use App\Http\Controllers\Admin\PayRules\ListController as ListPayRulesController;
use App\Http\Controllers\Admin\PayRules\ShowController as ShowPayRuleController;
use App\Http\Controllers\Admin\Profile\ReplaceDependentsController;
use App\Http\Controllers\Admin\Profile\ShowController as ShowProfileAdminController;
use App\Http\Controllers\Admin\Profile\UpsertProfileController;
use App\Http\Controllers\Attendance\Adjustments\SubmitController as SubmitAdjustmentController;
use App\Http\Controllers\Attendance\ListEmployeeAttendanceController;
use App\Http\Controllers\Attendance\ListMyAttendanceController;
use App\Http\Controllers\Attendance\ListMySummaryController;
use App\Http\Controllers\Attendance\PunchController;
use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\Documents\ShowCatalogController as ShowDocumentCatalogController;
use App\Http\Controllers\Auth\LogoutController;
use App\Http\Controllers\Auth\MeController;
use App\Http\Controllers\Cutoff\CloseCutoffController;
use App\Http\Controllers\Cutoff\ExportCutoffController;
use App\Http\Controllers\Cutoff\ListCutoffsController;
use App\Http\Controllers\Cutoff\ReopenCutoffController;
use App\Http\Controllers\Admin\Profile\DeleteIdentificationController;
use App\Http\Controllers\Admin\Profile\SaveIdentificationController;
use App\Http\Controllers\Employees\DownloadScanController;
use App\Http\Controllers\Employees\ListEmployeesController;
use App\Http\Controllers\Employees\ShowEmployeeController;
use App\Http\Controllers\Employees\ShowProfileController;
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
use App\Http\Controllers\Profile\ShowCatalogController;
use App\Http\Controllers\Profile\ShowMyProfileController;
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

        // The manager-facing redacted personnel file (M10a). Same prefix as the full read
        // under /admin below, deliberately different policy: contact and assignment only.
        Route::get('/employees/{employee}/profile', ShowProfileController::class);

        // The scanned government ID, streamed app-mediated — never a public/object URL.
        // Gated on viewFullProfile (self or the administering HR Admin), not
        // viewRedactedProfile: a manager's redacted resource never hands them an
        // identification id, so a manager reaching this route is a guess or an attack.
        Route::get('/employees/{employee}/identifications/{identification}/scan', DownloadScanController::class);

        Route::get('/me/attendance', ListMyAttendanceController::class);
        Route::get('/me/attendance/summary', ListMySummaryController::class);
        Route::get('/me/leave', ListMyLeaveController::class);
        Route::get('/me/profile', ShowMyProfileController::class);

        // Static reference data for the profile dropdowns — not scoped, not admin-gated.
        Route::get('/profile/catalog', ShowCatalogController::class);

        // Static reference data for the document dropdowns — not scoped, not admin-gated,
        // exactly like /profile/catalog above.
        Route::get('/documents/catalog', ShowDocumentCatalogController::class);

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
            // The profiler read surface (M8b Task 4) — company-wide, not scope-filtered
            // like the plain GET /employees above. Gated by ListEmployeesRequest /
            // ShowEmployeeRequest's is_system_admin authorize(), same 403-not-404 shape as
            // offices/departments/pay-rules/organizations below: nothing office-scoped to
            // 404 against, so a non-admin gets the default forbidden response.
            Route::get('/employees', ListEmployeesAdminController::class);
            Route::get('/employees/{employee}', ShowEmployeeAdminController::class);
            Route::post('/employees', CreateEmployeeController::class);
            Route::patch('/employees/{employee}', UpdateEmployeeController::class);
            Route::post('/employees/{employee}/user', ProvisionUserController::class);
            Route::post('/employees/{employee}/employment', RecordEmploymentController::class);

            // HR-Admin access management (M8c Task 2): couples the hr_admin_offices
            // pivot with the spatie 'HR Admin' role in one write (SetHrAdminOffices).
            // Same is_system_admin gating as the rest of this group; office_ids=[]
            // revokes HR-Admin entirely rather than leaving a dangling role/pivot.
            Route::post('/employees/{employee}/hr-offices', SetHrAdminOfficesController::class);

            // The personnel file (M10a). Unlike every other route in this group, these are
            // NOT is_system_admin-gated: the requirement is that HR ADMINS configure
            // profiles, so authorization runs through EmployeePolicy's viewFullProfile /
            // updateProfile, which pair the `employee.pii.edit` permission with the
            // hr_admin_offices pivot. Gate::before still grants a system admin everything.
            Route::get('/employees/{employee}/profile', ShowProfileAdminController::class);
            Route::put('/employees/{employee}/profile', UpsertProfileController::class);
            Route::put('/employees/{employee}/dependents', ReplaceDependentsController::class);

            // POST, not PUT, despite being an upsert: PHP parses a multipart body only on
            // POST. A PUT multipart/form-data arrives with an empty $_FILES and the scan
            // vanishes silently. See the M10a spec.
            Route::post('/employees/{employee}/identifications', SaveIdentificationController::class);
            Route::delete('/employees/{employee}/identifications/{identification}', DeleteIdentificationController::class);

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

            // Offices — the org tree's second tier, same is_system_admin gating as
            // organizations above (not OfficeScope: you can't scope-check an office by
            // itself). Archive-never-delete: no DELETE route, only archive/unarchive
            // toggles on the nullable archived_at column (M8a Task 3). The generic
            // AlreadyArchived/NotArchived exceptions these two throw are reused verbatim
            // by departments (Task 4).
            Route::get('/offices', ListOfficesController::class);
            Route::post('/offices', CreateOfficeController::class);
            Route::patch('/offices/{office}', UpdateOfficeController::class);
            Route::post('/offices/{office}/archive', ArchiveOfficeController::class);
            Route::post('/offices/{office}/unarchive', UnarchiveOfficeController::class);

            // Departments — the org tree's third tier, same is_system_admin gating and
            // archive-never-delete shape as offices above (M8a Task 4). code is unique
            // per (office_id, code), not globally, so DuplicateDepartmentCode's scope
            // differs from DuplicateOfficeCode; the AlreadyArchived/NotArchived
            // exceptions are reused verbatim with subjectType 'department'.
            Route::get('/departments', ListDepartmentsController::class);
            Route::post('/departments', CreateDepartmentController::class);
            Route::patch('/departments/{department}', UpdateDepartmentController::class);
            Route::post('/departments/{department}/archive', ArchiveDepartmentController::class);
            Route::post('/departments/{department}/unarchive', UnarchiveDepartmentController::class);

            // The read-only audit viewer (M8c Task 1) — a filterable, paginated window
            // over the Spatie activity log every LogsActivity model already writes to.
            // Same is_system_admin gating as the rest of this group: the log spans every
            // subject type company-wide, nothing to scope-check against a single office.
            Route::get('/activity', ListActivityController::class);

            // The document catalog (M10b-a). Unlike most of this group these are NOT
            // is_system_admin-gated: each FormRequest checks `manageCatalog`, so any HR Admin
            // may edit the catalog. It is company-wide reference data with no office to
            // scope by, which is why the denial is a plain 403 rather than the 404-not-403
            // shape used where an owner id sits in the URL.
            Route::get('/document-categories', ListDocumentCategoriesController::class);
            Route::post('/document-categories', CreateDocumentCategoryController::class);
            Route::patch('/document-categories/{category}', UpdateDocumentCategoryController::class);
            Route::delete('/document-categories/{category}', DeleteDocumentCategoryController::class);
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
