<?php

declare(strict_types=1);

namespace App\Domain\Payroll;

use App\Domain\Employment\EmploymentResolver;
use App\Models\CutoffPeriod;
use App\Models\DailyAttendanceSummary;
use App\Models\Employee;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Rolls a closed cutoff period's frozen summaries into a per-employee earnings breakdown, in
 * integer minutes + basis points. Pure query + in-memory aggregation, no writes — a domain-
 * Eloquent wrapper like ApprovalQueues.
 *
 * Reads by MEMBERSHIP (office_id + date range), never by status='locked', so a leaked computed
 * row or an incomplete day still appears (M7a's forward-note). A line's rule_version_id is its
 * parent summary's column (lines don't carry it); the (kind, applied_bp, rule_version_id) group
 * key pairs each line with its summary's version.
 */
final class PayrollExport
{
    private function __construct() {}

    public static function for(CutoffPeriod $period): PayrollExportData
    {
        $start = $period->start_date->toDateString();
        $end = $period->end_date->toDateString();

        /** @var Collection<int, DailyAttendanceSummary> $summaries */
        $summaries = DailyAttendanceSummary::query()
            ->with('lines')
            ->where('office_id', $period->office_id)
            ->whereBetween('date', [$start, $end])
            ->get();

        $employees = $summaries
            ->groupBy('employee_id')
            ->map(fn (Collection $rows, string $employeeId): PayrollEmployeeExport => self::forEmployee($employeeId, $rows, $period))
            ->values()
            ->all();

        return new PayrollExportData($period, $employees);
    }

    /** @param  Collection<int, DailyAttendanceSummary>  $rows */
    private static function forEmployee(string $employeeId, Collection $rows, CutoffPeriod $period): PayrollEmployeeExport
    {
        $employee = Employee::query()->findOrFail($employeeId);

        // Lines: flatten, pairing each with its summary's rule_version_id, then group + sum.
        $lines = $rows
            ->flatMap(fn (DailyAttendanceSummary $s): array => $s->lines
                ->map(fn ($line): array => [
                    'kind' => $line->kind,
                    'applied_bp' => $line->applied_bp,
                    'rule_version_id' => $s->rule_version_id,
                    'minutes' => $line->minutes,
                ])->all())
            ->groupBy(fn (array $l): string => $l['kind']->value.'|'.$l['applied_bp'].'|'.($l['rule_version_id'] ?? 'null'))
            ->map(fn (Collection $group): PayrollEarningsLine => new PayrollEarningsLine(
                kind: $group->first()['kind'],
                appliedBp: $group->first()['applied_bp'],
                ruleVersionId: $group->first()['rule_version_id'],
                minutes: $group->sum('minutes'),
            ))
            ->values()
            ->all();

        [$baseRateCents, $segments] = self::baseRate($employee, $rows, $period);

        return new PayrollEmployeeExport(
            employeeId: $employeeId,
            employeeNo: $employee->employee_no,
            baseRateCents: $baseRateCents,
            baseRateSegments: $segments,
            workedMinutes: (int) $rows->sum('worked_minutes'),
            lateMinutes: (int) $rows->sum('late_minutes'),
            undertimeMinutes: (int) $rows->sum('undertime_minutes'),
            unpaidOvertimeMinutes: (int) $rows->sum('unpaid_overtime_minutes'),
            lines: $lines,
            hasIncompleteDays: $rows->contains(fn (DailyAttendanceSummary $s): bool => $s->is_incomplete),
        );
    }

    /**
     * The period-end effective base rate + the distinct effective segments that priced in-period
     * days. Loads the employee's records once (no N+1) and resolves in-memory.
     *
     * @param  Collection<int, DailyAttendanceSummary>  $rows
     * @return array{0: ?int, 1: list<array{effective_from: string, base_rate_cents: int}>}
     */
    private static function baseRate(Employee $employee, Collection $rows, CutoffPeriod $period): array
    {
        $endRecord = EmploymentResolver::on($employee, Carbon::parse($period->end_date->toDateString()));
        $endRate = $endRecord?->base_rate_cents;

        // Distinct records effective on the in-period days this employee actually has summaries for.
        $segments = $rows
            ->map(fn (DailyAttendanceSummary $s) => EmploymentResolver::on($employee, Carbon::parse($s->date->toDateString())))
            ->filter()
            ->unique(fn ($record): string => $record->id)
            ->sortBy(fn ($record): string => $record->effective_from->toDateString())
            ->map(fn ($record): array => [
                'effective_from' => $record->effective_from->toDateString(),
                'base_rate_cents' => $record->base_rate_cents,
            ])
            ->values()
            ->all();

        return [$endRate, $segments];
    }
}
