<?php

declare(strict_types=1);

use App\Actions\Requests\ApproveRequest;
use App\Domain\Cutoff\CutoffState;
use App\Domain\Requests\RequestState;
use App\Domain\Requests\RequestType;
use App\Exceptions\Domain\CutoffLocked;
use App\Models\CutoffPeriod;
use App\Models\DailyAttendanceSummary;
use App\Models\Employee;
use App\Models\Office;
use App\Models\OvertimeDetail;
use App\Models\RecomputeRun;
use App\Models\Request;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/*
| Task 6, the load-bearing half: ApproveRequest reads the cutoff state (CutoffGuard) on the
| final hop UNDER the affected employee's row lock — the SAME per-employee lock CloseCutoff
| takes before freezing that employee's summaries. Without that lock, an approval whose
| final-hop cutoff read happens to interleave with a concurrent close can pass the guard
| (period still open at read time) and then land its effect on a day the close is
| simultaneously locking — a change written into a period that ends up closed.
|
| A single-process/sequential test cannot prove this: it passes identically whether or not
| the employee lock exists, because nothing is ever held open while a second attempt is in
| flight. This is the genuine two-connection proof, mirroring
| tests/Feature/Attendance/ApproveRequestConcurrencyTest.php and
| tests/Feature/Leave/LeaveEffectConcurrencyTest.php: a real, separate OS process (a second
| Postgres backend session) stands in for a CloseCutoff mid-flight — it takes the employee
| row lock, then (after signalling LOCKED) freezes the in-period summaries and closes the
| period, and commits. THIS process's own concurrent, real, unmodified
| ApproveRequest::execute() for a pending overtime request on a date in that period must
| genuinely BLOCK on the employee lock, then — once the holder commits the close — read the
| now-closed period and refuse with CutoffLocked, leaving the request un-approved and no
| effect applied.
|
| Deliberately NOT uses(RefreshDatabase::class): see the attendance file's header — a
| second, genuinely separate database connection can only see committed rows, so this file
| commits its fixtures for real and cleans them up by hand in a finally block.
*/

it('genuinely serializes an approval against a concurrent close through the employee lock, refusing with CutoffLocked', function (): void {
    $office = Office::factory()->create(['ip_allowlist' => null]);

    $managerUser = User::factory()->create();
    $manager = Employee::factory()->for($managerUser)->create(['current_office_id' => $office->id]);

    $reportUser = User::factory()->create();
    $report = Employee::factory()->for($reportUser)->create([
        'current_office_id' => $office->id,
        'current_reports_to_id' => $manager->id,
    ]);

    // An OPEN period the holder is about to close, and an in-period summary for the report.
    $period = CutoffPeriod::factory()->create([
        'office_id' => $office->id,
        'start_date' => '2026-07-01',
        'end_date' => '2026-07-15',
        'state' => 'open',
    ]);
    $summary = DailyAttendanceSummary::factory()->create([
        'employee_id' => $report->id,
        'office_id' => $office->id,
        'date' => '2026-07-10',
        'status' => 'computed',
        'is_incomplete' => false,
    ]);

    // A pending overtime request whose date lands in the period — its final (only) hop is
    // this manager's decision.
    $request = Request::factory()->for($report)->create([
        'type' => RequestType::Overtime,
        'state' => RequestState::Pending,
    ]);
    OvertimeDetail::query()->create(['request_id' => $request->id, 'date' => '2026-07-10', 'minutes' => 120]);

    $holderScript = __DIR__.'/Support/close_lock_holder.php';
    $holdMs = 500;

    $cleanup = function () use ($request, $summary, $period, $manager, $report, $managerUser, $reportUser, $office): void {
        // Office::factory() and Employee::factory() each default organization_id to their
        // own lazy Organization::factory() when it isn't overridden — true here for all
        // three of $office, $manager, and $report — so this real, committed run leaves
        // behind three organizations unless captured and deleted explicitly. Office->
        // organization has cascadeOnDelete, but only in that direction (deleting the
        // office never deletes its parent org), so it must be done by hand, and captured
        // before the rows that carry these ids are deleted below.
        $orgIds = collect([$office->organization_id, $manager->organization_id, $report->organization_id])
            ->unique()->values();

        RecomputeRun::where('trigger_id', $request->id)->delete();
        OvertimeDetail::where('request_id', $request->id)->delete();
        Request::whereKey($request->id)->delete();
        DailyAttendanceSummary::whereKey($summary->id)->delete();
        CutoffPeriod::whereKey($period->id)->delete();
        Employee::whereIn('id', [$manager->id, $report->id])->delete();
        User::whereIn('id', [$managerUser->id, $reportUser->id])->delete();
        Office::whereKey($office->id)->delete();
        DB::table('organizations')->whereIn('id', $orgIds)->delete();
    };

    $proc = proc_open(
        ['php', $holderScript, $report->id, $period->id, (string) $holdMs],
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
        $caught = null;

        try {
            // The real, unmodified action — this is what the employee lock must serialize
            // against the concurrent close.
            app(ApproveRequest::class)->execute($request->fresh(), $managerUser);
        } catch (CutoffLocked $e) {
            $caught = $e;
        }

        $elapsedMs = (microtime(true) - $start) * 1000;

        // The holder signalled LOCKED before holding for $holdMs more, so a call that
        // returns near-instantly did NOT genuinely contend for the employee lock — it would
        // mean the lock isn't real, or this connection saw a stale (still-open) read instead
        // of blocking on it and thus never refused.
        expect($elapsedMs)->toBeGreaterThan($holdMs * 0.5)
            ->and($caught)->not->toBeNull()
            ->and($caught->errorCode())->toBe('cutoff_locked')
            ->and($caught->httpStatus())->toBe(422)
            ->and($caught->details()['date'])->toBe('2026-07-10');

        $doneLine = fgets($pipes[1]);
        expect(trim((string) $doneLine))->toBe('DONE');

        $exitCode = proc_close($proc);
        $proc = null;
        expect($exitCode)->toBe(0);

        // The holder closed the period and locked the summary; THIS process's approval
        // refused, so the request is untouched and no overtime recompute was ever enqueued.
        expect($period->fresh()->state)->toBe(CutoffState::Closed)
            ->and($summary->fresh()->status)->toBe('locked')
            ->and(RecomputeRun::where('trigger_id', $request->id)->count())->toBe(0);

        $fresh = $request->fresh();
        expect($fresh->state)->toBe(RequestState::Pending)
            ->and($fresh->decided_by)->toBeNull()
            ->and($fresh->decided_at)->toBeNull();
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
