<?php

declare(strict_types=1);

namespace App\Http\Controllers\Attendance;

use App\Domain\Attendance\AttendanceMonth;
use App\Exceptions\Domain\NotAnEmployee;
use App\Models\AttendanceLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

final class ListMyAttendanceController
{
    public function __invoke(Request $request): JsonResponse
    {
        $employee = $request->user()->employee;

        if ($employee === null) {
            throw new NotAnEmployee();
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
