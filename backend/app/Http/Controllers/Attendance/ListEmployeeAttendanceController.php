<?php

declare(strict_types=1);

namespace App\Http\Controllers\Attendance;

use App\Domain\Attendance\AttendanceMonth;
use App\Domain\Scope\EmployeeScope;
use App\Models\AttendanceLog;
use App\Models\Employee;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

final class ListEmployeeAttendanceController
{
    public function __invoke(Request $request, Employee $employee): JsonResponse
    {
        // 404, not 403: "this exists but isn't yours" leaks the org chart, so an
        // out-of-scope subject is indistinguishable from a nonexistent id. Checked BEFORE
        // any punch is loaded, so scope is enforced before data is ever touched.
        if (! EmployeeScope::visibleTo($request->user())->whereKey($employee->id)->exists()) {
            throw new NotFoundHttpException();
        }

        $month = Carbon::parse(($request->string('month')->toString() ?: now()->format('Y-m')).'-01');

        // A one-day margin each side so a punch whose LOCAL date falls in the month but
        // whose UTC instant sits just outside it is still included; grouping keys by local
        // date, and the response only surfaces dates the punches actually fall on.
        $logs = AttendanceLog::query()
            ->with('office')
            ->where('employee_id', $employee->id)
            ->whereBetween('punched_at', [
                $month->copy()->startOfMonth()->subDay(),
                $month->copy()->endOfMonth()->addDay(),
            ])
            ->get();

        return response()->json(['data' => AttendanceMonth::group($logs)]);
    }
}
