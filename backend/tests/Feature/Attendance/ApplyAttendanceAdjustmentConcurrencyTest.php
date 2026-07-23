<?php

declare(strict_types=1);

use App\Actions\Requests\ApproveRequest;
use App\Domain\Attendance\AdjustmentOperation;
use App\Domain\Requests\RequestState;
use App\Exceptions\Domain\InvalidAdjustmentTarget;
use App\Models\AttendanceAdjustmentDetail;
use App\Models\AttendanceAnnulment;
use App\Models\AttendanceLog;
use App\Models\Employee;
use App\Models\Office;
use App\Models\Request;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/*
| Final whole-branch review, Important finding: ApproveRequest's lockForUpdate serializes
| two concurrent approvals of the SAME request (proven by ApproveRequestConcurrencyTest),
| but does NOTHING for two DIFFERENT void requests that both target the SAME
| attendance_logs row — each locks a different `requests` row, so they never contend on
| that lock. Before the fix, assertAnnullable's target-log read was a plain unlocked
| find(): both approvals could pass the "not already annulled" check, the first's
| RecordAnnulment insert would succeed, and the second's would hit the
| unique(attendance_log_id) constraint as an uncaught QueryException (23505) -> the
| catch-all -> HTTP 500. The sequential version of this exact scenario (see
| AdjustmentTransitionsTest, "422s an approval whose target was already annulled...")
| correctly 422s; only the concurrent race surfaced the wrong status.
|
| This file is the genuine two-connection proof, mirroring ApproveRequestConcurrencyTest's
| pattern exactly: a real second OS process (its own Laravel app, its own Postgres backend
| session — not a second logical connection sharing this one's session) locks the first
| void request's row AND the shared target log row, signals once that target-row lock is
| held, holds it open, then applies the annulment and commits. THIS process's concurrent
| approval of the SECOND void request — via the real, unmodified ApproveRequest::execute()
| — must block on that target-row lock at the database level, then, once the holder
| commits, re-read the now-committed annulment and take the clean 422 branch, never a 500.
|
| Deliberately NOT `uses(RefreshDatabase::class)`: see ApproveRequestConcurrencyTest's file
| header for why — a second, genuinely separate connection can only see committed rows, so
| this file commits its fixtures for real and cleans them up by hand in a finally block.
*/

it('serializes two DIFFERENT void requests targeting the SAME log through the target-row lock, 422 not 500', function (): void {
    $office = Office::factory()->create(['ip_allowlist' => null]);
    $managerUser = User::factory()->create();
    $manager = Employee::factory()->for($managerUser)->create(['current_office_id' => $office->id]);
    $reportUser = User::factory()->create();
    $report = Employee::factory()->for($reportUser)->create([
        'current_office_id' => $office->id,
        'current_reports_to_id' => $manager->id,
    ]);

    // office_id pinned to $office: AttendanceLog::factory()'s default otherwise mints a
    // brand-new Office via Office::factory(), which — unlike AdjustmentTransitionsTest's
    // sequential version of this scenario, which runs under RefreshDatabase — would leak a
    // real, uncleaned row here, since this file deliberately commits for real.
    $target = AttendanceLog::factory()->create(['employee_id' => $report->id, 'office_id' => $office->id]);

    $firstVoid = Request::factory()->for($report)->create();
    AttendanceAdjustmentDetail::factory()->for($firstVoid)->create([
        'operation' => AdjustmentOperation::Void,
        'target_log_id' => $target->id,
        'direction' => null,
        'punched_at' => null,
    ]);

    $secondVoid = Request::factory()->for($report)->create();
    AttendanceAdjustmentDetail::factory()->for($secondVoid)->create([
        'operation' => AdjustmentOperation::Void,
        'target_log_id' => $target->id,
        'direction' => null,
        'punched_at' => null,
    ]);

    $holderScript = __DIR__.'/Support/annul_lock_holder.php';
    $holdMs = 500;

    $proc = proc_open(
        ['php', $holderScript, $firstVoid->id, $managerUser->id, (string) $holdMs],
        [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $pipes,
        base_path(),
    );

    if (! is_resource($proc)) {
        // Cleanup then fail loudly — proc_open itself refused to spawn.
        Request::whereIn('id', [$firstVoid->id, $secondVoid->id])->delete();
        AttendanceLog::whereKey($target->id)->delete();
        Employee::whereIn('id', [$manager->id, $report->id])->delete();
        User::whereIn('id', [$managerUser->id, $reportUser->id])->delete();
        Office::whereKey($office->id)->delete();

        $this->fail('proc_open failed to spawn the lock-holder process.');
    }

    try {
        // Bounded wait for the child's "LOCKED" signal — never an unbounded hang. If the
        // child fails to boot or lock within 5s, something is genuinely broken (not
        // ordinary contention) and we fail with its stderr rather than hang the suite.
        stream_set_timeout($pipes[1], 5);
        $signal = fgets($pipes[1]);
        $timedOut = stream_get_meta_data($pipes[1])['timed_out'] ?? false;

        if ($timedOut || trim((string) $signal) !== 'LOCKED') {
            $stderr = stream_get_contents($pipes[2]);
            proc_terminate($proc, 9);

            $this->fail('Lock-holder process never signalled LOCKED (got: '.var_export($signal, true).").\nstderr:\n{$stderr}");
        }

        // A defensive backstop, not the mechanism under test: if the row lock somehow
        // never resolves, fail after 5s of real waiting instead of hanging CI forever.
        // Reset in the finally block below so it never leaks into later tests sharing
        // this same connection.
        DB::statement("SET lock_timeout = '5000ms'");

        $start = microtime(true);
        $caught = null;

        try {
            // The real, unmodified action chain — ApproveRequest -> ApplyAttendanceAdjustment
            // -> assertAnnullable. This is the fix under test: assertAnnullable's lockForUpdate
            // on the shared target log row is what makes this block instead of racing ahead.
            app(ApproveRequest::class)->execute($secondVoid->fresh(), $managerUser);
        } catch (InvalidAdjustmentTarget $e) {
            $caught = $e;
        }

        $elapsedMs = (microtime(true) - $start) * 1000;

        // The holder signalled LOCKED before holding for $holdMs more, so a call that
        // returns near-instantly did NOT genuinely contend for the target-row lock — it
        // would mean the lock isn't real, or this connection saw a stale/uncommitted read
        // instead of blocking on it. A generous margin below $holdMs keeps this from being
        // timing-flaky while still being a meaningful proof of blocking.
        expect($elapsedMs)->toBeGreaterThan($holdMs * 0.5)
            ->and($caught)->not->toBeNull()
            ->and($caught->errorCode())->toBe('invalid_adjustment_target')
            ->and($caught->httpStatus())->toBe(422);

        $doneLine = fgets($pipes[1]);
        expect(trim((string) $doneLine))->toBe('DONE');

        $exitCode = proc_close($proc);
        $proc = null;
        expect($exitCode)->toBe(0);

        // The holder recorded the one real annulment; this process's concurrent second
        // approval only ever saw it already committed and rolled itself all the way back.
        // Exactly one annulment, ever — the unique(attendance_log_id) invariant never had
        // a second insert to reject.
        expect(AttendanceAnnulment::where('attendance_log_id', $target->id)->count())->toBe(1);

        $freshFirst = $firstVoid->fresh();
        expect($freshFirst->state)->toBe(RequestState::Approved)
            ->and($freshFirst->decided_by)->toBe($managerUser->id);

        $freshSecond = $secondVoid->fresh();
        expect($freshSecond->state)->toBe(RequestState::Pending)
            ->and($freshSecond->decided_by)->toBeNull()
            ->and($freshSecond->decided_at)->toBeNull();
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

        // Manual cleanup — see the file header on why RefreshDatabase can't do this.
        AttendanceAnnulment::where('attendance_log_id', $target->id)->delete();
        Request::whereIn('id', [$firstVoid->id, $secondVoid->id])->delete();
        AttendanceLog::whereKey($target->id)->delete();
        Employee::whereIn('id', [$manager->id, $report->id])->delete();
        User::whereIn('id', [$managerUser->id, $reportUser->id])->delete();
        Office::whereKey($office->id)->delete();
    }
});
