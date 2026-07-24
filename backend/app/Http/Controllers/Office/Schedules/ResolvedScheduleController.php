<?php

declare(strict_types=1);

namespace App\Http\Controllers\Office\Schedules;

use App\Domain\Schedule\ResolvedSchedule;
use App\Domain\Schedule\ScheduleResolver;
use App\Domain\Scope\OfficeScope;
use App\Http\Requests\ResolvedScheduleRequest;
use App\Models\Employee;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Carbon;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * ScheduleResolver over HTTP: every date of the given month, resolved for one employee.
 * `OfficeHasNoDefaultTemplate` / `EmployeeHasNoOffice` are left to propagate — the
 * envelope maps them to 422, the same as any other domain exception.
 */
final class ResolvedScheduleController
{
    public function __invoke(ResolvedScheduleRequest $request, ScheduleResolver $resolver): JsonResponse
    {
        $employeeId = $request->string('employee')->toString();

        // 404, not 403: an employee outside any office the caller administers must be
        // indistinguishable from a fabricated id. A null current_office_id can never
        // satisfy OfficeScope::administers, so an unassigned employee 404s the same way.
        $employee = Employee::query()->find($employeeId);
        if ($employee === null || ! OfficeScope::administers($request->user(), (string) $employee->current_office_id)) {
            throw new NotFoundHttpException;
        }

        $month = $request->string('month')->toString();
        $firstOfMonth = Carbon::parse($month.'-01');

        $schedule = [];
        for ($day = 1; $day <= $firstOfMonth->daysInMonth; $day++) {
            $date = $firstOfMonth->copy()->day($day)->toDateString();
            $schedule[$date] = self::toWireShape($resolver->resolve($employee, $date));
        }

        return response()->json(['data' => $schedule]);
    }

    /** @return array<string, mixed> */
    private static function toWireShape(ResolvedSchedule $resolved): array
    {
        return [
            'is_rest' => $resolved->isRestDay,
            'start_minute' => $resolved->startMinute,
            'end_minute' => $resolved->endMinute,
            'break_minutes' => $resolved->breakMinutes,
            'scheduled_minutes' => $resolved->scheduledMinutes,
            'source' => $resolved->source->value,
        ];
    }
}
