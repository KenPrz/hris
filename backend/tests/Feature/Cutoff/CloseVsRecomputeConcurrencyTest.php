<?php

declare(strict_types=1);

use App\Actions\Cutoff\CloseCutoff;
use App\Actions\Cutoff\CloseCutoffInput;
use App\Domain\Attendance\PunchDirection;
use App\Domain\Cutoff\CutoffState;
use App\Models\AttendanceLog;
use App\Models\CutoffPeriod;
use App\Models\DailyAttendanceSummary;
use App\Models\DailySummaryLine;
use App\Models\Department;
use App\Models\Employee;
use App\Models\EmploymentRecord;
use App\Models\Office;
use App\Models\PayRule;
use App\Models\PayRuleDayRate;
use App\Models\ShiftTemplate;
use App\Models\ShiftTemplateDay;
use App\Models\User;
use Illuminate\Support\Facades\DB;

require_once __DIR__.'/../Compute/support.php';

/*
| Task 7, the load-bearing half: ComputeDailySummary reads the covering cutoff period UNDER
| the per-employee row lock it already takes — the SAME lock CloseCutoff takes before freezing
| that employee's summaries. Because both serialize on that lock, a close racing a recompute
| has exactly two outcomes and no third: either the close committed first (the recompute then
| sees `closed` and skips — proven single-process in ComputeSkipsClosedPeriodTest), or the
| recompute committed first (the close then blocks on the lock, and freezes the recompute's
| fresh result to `locked` — never leaving it unlocked). This file proves the second: the
| block-and-serialize, with the close winning the freeze.
|
| A single-process/sequential test cannot prove this: it passes identically whether or not the
| employee lock exists, because nothing is ever held open while a second attempt is in flight.
| This is the genuine two-connection proof, mirroring CloseVsApproveConcurrencyTest and
| tests/Feature/Attendance/ApproveRequestConcurrencyTest.php: a real, separate OS process (a
| second Postgres backend session) stands in for a ComputeDailySummary recompute mid-flight —
| it takes the employee row lock, signals LOCKED, holds, then recomputes and commits. THIS
| process's own concurrent, real, unmodified CloseCutoff::execute() for that period must
| genuinely BLOCK on the employee lock, then — once the holder commits — freeze the recompute's
| result, leaving the summary `locked`.
|
| Deliberately NOT uses(RefreshDatabase::class): a second, genuinely separate database
| connection can only see committed rows, so this file commits its fixtures for real and
| cleans them up by hand in a finally block.
*/

it('genuinely serializes a close against a concurrent recompute through the employee lock, freezing the recompute result to locked', function (): void {
    $office = computeOffice();
    $employee = computeEmployee($office);
    $rule = seedPayRule();

    $date = '2026-07-10'; // Friday, inside the 2026-07-01..15 window

    // Two paired punches produce a real, complete `computed` summary (RecordPunch computes on
    // afterCommit) — the in-period row the close will freeze and the holder will recompute.
    recordManualPunch($employee, $office, $date, '08:00', PunchDirection::In);
    recordManualPunch($employee, $office, $date, '17:00', PunchDirection::Out);

    $summary = DailyAttendanceSummary::query()
        ->where('employee_id', $employee->id)->whereDate('date', $date)->firstOrFail();
    expect($summary->status)->toBe('computed')->and($summary->is_incomplete)->toBeFalse();

    // The OPEN period the close will firstOrCreate onto (matched by office_id + start_date).
    $period = CutoffPeriod::factory()->create([
        'office_id' => $office->id,
        'start_date' => '2026-07-01',
        'end_date' => '2026-07-15',
        'state' => 'open',
    ]);

    $actor = User::factory()->create();

    $holderScript = __DIR__.'/Support/recompute_lock_holder.php';
    $holdMs = 500;

    $cleanup = function () use ($employee, $office, $period, $rule, $actor): void {
        $summaryIds = DailyAttendanceSummary::where('employee_id', $employee->id)->pluck('id');
        DailySummaryLine::whereIn('summary_id', $summaryIds)->delete();
        DailyAttendanceSummary::where('employee_id', $employee->id)->delete();
        AttendanceLog::where('employee_id', $employee->id)->delete();
        CutoffPeriod::whereKey($period->id)->delete();
        PayRuleDayRate::where('pay_rule_id', $rule->id)->delete();
        PayRule::whereKey($rule->id)->delete();
        EmploymentRecord::where('employee_id', $employee->id)->delete();
        $deptId = $employee->current_department_id;
        Employee::whereKey($employee->id)->delete();
        User::whereKey($actor->id)->delete();
        if ($deptId !== null) {
            Department::whereKey($deptId)->delete();
        }
        // Null the office's default template before deleting the template it points at.
        $templateId = $office->fresh()?->default_shift_template_id;
        Office::whereKey($office->id)->update(['default_shift_template_id' => null]);
        if ($templateId !== null) {
            ShiftTemplateDay::where('shift_template_id', $templateId)->delete();
            ShiftTemplate::whereKey($templateId)->delete();
        }
        $orgId = $office->organization_id;
        Office::whereKey($office->id)->delete();
        DB::table('organizations')->where('id', $orgId)->delete();
    };

    $proc = proc_open(
        ['php', $holderScript, $employee->id, $date, (string) $holdMs],
        [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $pipes,
        base_path(),
    );

    if (! is_resource($proc)) {
        $cleanup();
        $this->fail('proc_open failed to spawn the lock-holder process.');
    }

    try {
        // Bounded wait for the child's "LOCKED" signal — never an unbounded hang.
        stream_set_timeout($pipes[1], 5);
        $signal = fgets($pipes[1]);
        $timedOut = stream_get_meta_data($pipes[1])['timed_out'] ?? false;

        if ($timedOut || trim((string) $signal) !== 'LOCKED') {
            $stderr = stream_get_contents($pipes[2]);
            proc_terminate($proc, 9);

            $this->fail('Lock-holder process never signalled LOCKED (got: '.var_export($signal, true).").\nstderr:\n{$stderr}");
        }

        // Defensive backstop, not the mechanism under test — reset in the finally block.
        DB::statement("SET lock_timeout = '5000ms'");

        $start = microtime(true);

        // The real, unmodified action — its per-employee freeze lock must serialize against
        // the concurrent recompute holding that same lock.
        $closed = app(CloseCutoff::class)->execute(new CloseCutoffInput(
            officeId: $office->id,
            periodStart: '2026-07-01',
            actorId: $actor->id,
        ));

        $elapsedMs = (microtime(true) - $start) * 1000;

        // The holder signalled LOCKED before holding for $holdMs more, so a close that returns
        // near-instantly did NOT genuinely contend for the employee lock — it would mean the
        // lock isn't real (freeze not serialized against the recompute).
        expect($elapsedMs)->toBeGreaterThan($holdMs * 0.5)
            ->and($closed->state)->toBe(CutoffState::Closed);

        $doneLine = fgets($pipes[1]);
        expect(trim((string) $doneLine))->toBe('DONE');

        $exitCode = proc_close($proc);
        $proc = null;
        expect($exitCode)->toBe(0);

        // The recompute committed first (a fresh `computed` row); the close serialized behind
        // it and froze THAT result — so the final in-period summary is `locked`, not left
        // unlocked at `computed`.
        $final = DailyAttendanceSummary::query()
            ->where('employee_id', $employee->id)->whereDate('date', $date)->firstOrFail();

        expect($final->status)->toBe('locked')
            ->and($period->fresh()->state)->toBe(CutoffState::Closed);
    } finally {
        if (isset($pipes[1]) && is_resource($pipes[1])) {
            fclose($pipes[1]);
        }
        if (isset($pipes[2]) && is_resource($pipes[2])) {
            fclose($pipes[2]);
        }
        if (is_resource($proc)) {
            proc_terminate($proc, 9);
            proc_close($proc);
        }

        DB::statement('SET lock_timeout = DEFAULT');

        $cleanup();
    }
});
