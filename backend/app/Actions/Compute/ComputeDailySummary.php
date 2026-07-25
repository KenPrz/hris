<?php

declare(strict_types=1);

namespace App\Actions\Compute;

use App\Domain\Attendance\EffectivePunches;
use App\Domain\Compute\DailyComputation;
use App\Domain\Compute\DailyComputationInput;
use App\Domain\Employment\EmploymentResolver;
use App\Domain\Pay\DayType;
use App\Domain\Schedule\ScheduleResolver;
use App\Models\DailyAttendanceSummary;
use App\Models\DailySummaryLine;
use App\Models\Employee;
use App\Models\Holiday;
use App\Models\PayRule;
use App\Support\PayRatesFactory;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Resolves one employee-day's business context — day type, schedule, Art. 82 status, the
 * effective pay_rules version, and the day's effective punches — hands it to the pure
 * DailyComputation calculator, and idempotently persists the result as one
 * daily_attendance_summaries row plus its daily_summary_lines.
 *
 * Idempotent by construction: the persist step always deletes any existing summary for
 * (employee, date) before inserting the fresh one inside the same transaction, so calling
 * this twice for the same day yields exactly one summary and never a duplicate line — no
 * upsert/on-conflict trickery needed, `daily_attendance_summaries.unique(employee_id, date)`
 * is simply never violated.
 *
 * Context resolution, one piece at a time:
 *  - Art. 82 exemption and the office a holiday is checked against both come from the
 *    EmploymentRecord effective ON THE DATE (greatest effective_from <= date), never from
 *    Employee's current_* cache — a promotion or an exemption change must not reinterpret
 *    a past day. A day before the employee's first employment record (or an employee with
 *    none at all) falls back to Employee::current_office_id for the holiday lookup and to
 *    "not exempt" — there is nothing effective-dated to read yet.
 *  - Day type is Ordinary unless a Holiday row exists for that (office, date).
 *  - The schedule comes from ScheduleResolver. A rest day reports null start/break: null
 *    start is passed straight through so DailyComputation charges zero lateness against a
 *    day nobody was scheduled to start (never a phantom ~8h against minute 0); null break
 *    reads as 0 here (0 is the correct break for an unworked/rest day). A rest day's
 *    scheduledMinutes is 0 for undertime/storage purposes, but the regular/overtime
 *    boundary this action hands DailyComputation is the statutory 8h (480) instead — see
 *    $overtimeThresholdMinutes below — so a rest day worked still prices its first 8h at
 *    rest-day base, not overtime.
 *  - The pay_rules version is the greatest effective_from <= date. When one exists, its
 *    id becomes rule_version_id and its rates price every line. When none exists yet for
 *    the date, the statutory floor (PayRatesFactory::statutory()) still lets
 *    DailyComputation compute accurate worked/late/undertime minutes, but nothing it
 *    prices is persisted — this action never attributes a line to a rule version that was
 *    not actually configured. Per the schema/spec invariant, a summary WITH lines always
 *    carries a non-null rule_version_id, so both "no configured version" and "the
 *    calculator itself produced no lines" (incomplete day, an unworked rest day, an
 *    unworked ordinary/special day, ...) collapse to the same persisted shape: no lines,
 *    rule_version_id null.
 */
final class ComputeDailySummary
{
    public function execute(Employee $employee, string $date): DailyAttendanceSummary
    {
        $onDate = Carbon::parse($date);

        $employmentRecord = EmploymentResolver::on($employee, $onDate);
        $officeId = $employmentRecord?->office_id ?? $employee->current_office_id;
        $isArt82Exempt = $employmentRecord?->is_art82_exempt ?? false;

        $dayType = $officeId !== null
            ? (Holiday::query()->where('office_id', $officeId)->whereDate('date', $date)->first()?->day_type ?? DayType::Ordinary)
            : DayType::Ordinary;

        $schedule = (new ScheduleResolver)->resolve($employee, $date);

        $payRule = PayRule::query()
            ->whereDate('effective_from', '<=', $date)
            ->orderByDesc('effective_from')
            ->first();

        $rates = $payRule !== null ? PayRatesFactory::fromVersion($payRule) : PayRatesFactory::statutory();

        // The regular/overtime boundary is the actual scheduled length on a working day,
        // but the statutory 8h (480) on a rest day (scheduledMinutes 0) — a rest-day
        // worker's first 8 hours are still rest-day-worked BASE, not overtime. Zero would
        // put the boundary at the start of the day and mis-price every worked minute as OT.
        $overtimeThresholdMinutes = $schedule->scheduledMinutes > 0 ? $schedule->scheduledMinutes : 480;

        $computed = DailyComputation::compute(new DailyComputationInput(
            punches: EffectivePunches::forDate($employee, $date),
            dayType: $dayType,
            isRestDay: $schedule->isRestDay,
            scheduledMinutes: $schedule->scheduledMinutes,
            overtimeThresholdMinutes: $overtimeThresholdMinutes,
            scheduledStartMinute: $schedule->startMinute,
            breakMinutes: $schedule->breakMinutes ?? 0,
            isArt82Exempt: $isArt82Exempt,
            rates: $rates,
        ));

        // Only ever persist lines priced against a pay_rules version that was actually
        // configured for this date — never the statutory-floor stand-in above.
        $lines = $payRule !== null ? $computed->lines : [];

        return DB::transaction(function () use ($employee, $date, $dayType, $schedule, $isArt82Exempt, $payRule, $computed, $lines): DailyAttendanceSummary {
            // Serialize concurrent computes of the same employee-day — two rapid punches each
            // trigger a compute (Task 6), and an unlocked delete-then-insert would let both pass
            // the delete and race the unique(employee_id, date) insert into a raw 500. Locking the
            // employee row first makes the second compute wait, then cleanly replace. Mirrors
            // CreateHoliday / ApplyAttendanceAdjustment.
            Employee::query()->lockForUpdate()->findOrFail($employee->id);

            DailyAttendanceSummary::query()
                ->where('employee_id', $employee->id)
                ->whereDate('date', $date)
                ->delete();

            $summary = DailyAttendanceSummary::query()->create([
                'employee_id' => $employee->id,
                'date' => $date,
                'day_type' => $dayType,
                'is_rest_day' => $schedule->isRestDay,
                'scheduled_minutes' => $schedule->scheduledMinutes,
                'is_art82_exempt' => $isArt82Exempt,
                'rule_version_id' => $lines !== [] ? $payRule?->id : null,
                'worked_minutes' => $computed->workedMinutes,
                'late_minutes' => $computed->lateMinutes,
                'undertime_minutes' => $computed->undertimeMinutes,
                'is_incomplete' => $computed->isIncomplete,
                'status' => 'computed',
                'computed_at' => now(),
            ]);

            foreach ($lines as $line) {
                DailySummaryLine::query()->create([
                    'summary_id' => $summary->id,
                    'kind' => $line->kind,
                    'minutes' => $line->minutes,
                    'applied_bp' => $line->appliedBp,
                ]);
            }

            return $summary->load('lines');
        });
    }
}
