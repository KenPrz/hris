<?php

declare(strict_types=1);

namespace App\Http\Controllers\Attendance;

use App\Exceptions\Domain\NotAnEmployee;
use App\Http\Requests\ListMySummaryRequest;
use App\Http\Resources\DailySummaryResource;
use App\Models\DailyAttendanceSummary;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Carbon;

final class ListMySummaryController
{
    public function __invoke(ListMySummaryRequest $request): JsonResponse
    {
        $employee = $request->user()->employee;

        if ($employee === null) {
            throw new NotAnEmployee();
        }

        $month = Carbon::parse($request->string('month')->toString().'-01');

        $summaries = DailyAttendanceSummary::query()
            ->where('employee_id', $employee->id)
            ->whereBetween('date', [
                $month->copy()->startOfMonth()->toDateString(),
                $month->copy()->endOfMonth()->toDateString(),
            ])
            ->with('lines')
            ->orderBy('date')
            ->get();

        return response()->json(['data' => DailySummaryResource::collection($summaries)]);
    }
}
