<?php

declare(strict_types=1);

namespace App\Http\Controllers\Leave;

use App\Domain\Leave\LeaveBalances;
use App\Domain\Leave\LeaveUnit;
use App\Domain\Scope\EmployeeScope;
use App\Http\Resources\LeaveBalanceResource;
use App\Models\Employee;
use App\Models\LeaveType;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * An overseen employee's leave balances — self, direct reports, or HR-office members (see
 * EmployeeScope). This is a broader read than granting (OfficeScope::administers, HR-only
 * — see GrantController): a manager may VIEW a report's balances even though only HR may
 * credit them. 404, not 403: an out-of-scope subject and a nonexistent one must be
 * indistinguishable to the caller. Same body as ListMyLeaveController otherwise — see there
 * for the absent-key-means-zero merge against active leave types.
 */
final class ListEmployeeLeaveController
{
    public function __invoke(Request $request, Employee $employee): JsonResponse
    {
        // Checked BEFORE any balance is computed, so scope is enforced before data is ever
        // touched.
        if (! EmployeeScope::visibleTo($request->user())->whereKey($employee->id)->exists()) {
            throw new NotFoundHttpException();
        }

        $office = $employee->currentOffice;

        $leaveTypes = LeaveType::query()
            ->where('office_id', $employee->current_office_id)
            ->where('deducts_balance', true)
            ->where('is_active', true)
            ->orderBy('name')
            ->get();

        $balances = LeaveBalances::forEmployee($employee);

        $rows = $leaveTypes
            ->map(function (LeaveType $leaveType) use ($balances, $office, $request): array {
                $minutes = $balances[$leaveType->id] ?? 0;

                return (new LeaveBalanceResource($leaveType, $minutes, LeaveUnit::readable($minutes, $office->minutes_per_leave_day)))
                    ->resolve($request);
            })
            ->values()
            ->all();

        return response()->json(['data' => $rows]);
    }
}
