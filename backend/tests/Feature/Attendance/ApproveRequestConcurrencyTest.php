<?php

declare(strict_types=1);

use App\Actions\Requests\ApproveRequest;
use App\Domain\Attendance\AdjustmentOperation;
use App\Domain\Attendance\PunchDirection;
use App\Domain\Requests\RequestState;
use App\Exceptions\Domain\RequestNotPending;
use App\Models\AttendanceAdjustmentDetail;
use App\Models\AttendanceLog;
use App\Models\Employee;
use App\Models\Office;
use App\Models\Request;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/*
| Task 7 review Finding 2: the sequential test in AdjustmentTransitionsTest proves the
| PENDING STATE GUARD (a second approval after the first has committed 409s) but never
| proves the row LOCK itself — a single PHP process calling the endpoint twice in a row
| never contends for anything, because nothing is held open concurrently. This file is
| the genuine two-connection proof: a real, separate OS process (a second Postgres
| backend session, not a second logical connection sharing this one's session) takes
| ApproveRequest's exact row lock and holds it open; THIS process's own concurrent call
| to the real, unmodified ApproveRequest::execute() must actually block on that lock at
| the database level, then — once the holder commits — see the row already approved and
| take the 409 branch, with exactly one punch on the ledger.
|
| Deliberately NOT `uses(RefreshDatabase::class)`: that trait wraps every test in an
| outer transaction, which would hide this test's seeded rows from the second, genuinely
| separate database connection the child process opens — a second connection can only
| see committed rows. So this file commits its fixtures for real and cleans them up by
| hand in a finally block, to leave the shared test database exactly as it found it for
| every other file in the suite.
*/

it('genuinely serializes two concurrent approvals through the row lock, not just the state guard', function (): void {
    $office = Office::factory()->create(['ip_allowlist' => null]);
    $managerUser = User::factory()->create();
    $manager = Employee::factory()->for($managerUser)->create(['current_office_id' => $office->id]);
    $reportUser = User::factory()->create();
    $report = Employee::factory()->for($reportUser)->create([
        'current_office_id' => $office->id,
        'current_reports_to_id' => $manager->id,
    ]);

    $request = Request::factory()->for($report)->create();
    AttendanceAdjustmentDetail::factory()->for($request)->create([
        'operation' => AdjustmentOperation::Add,
        'target_log_id' => null,
        'direction' => PunchDirection::In,
        'punched_at' => '2026-07-20T08:00:00Z',
    ]);

    $holderScript = __DIR__.'/Support/approve_lock_holder.php';
    $holdMs = 500;

    $proc = proc_open(
        ['php', $holderScript, $request->id, $managerUser->id, (string) $holdMs],
        [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $pipes,
        base_path(),
    );

    if (! is_resource($proc)) {
        // Cleanup then fail loudly — proc_open itself refused to spawn.
        AttendanceLog::where('employee_id', $report->id)->delete();
        Request::whereKey($request->id)->delete();
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
            // The real, unmodified action — this is the thing Finding 2 is about.
            app(ApproveRequest::class)->execute($request->fresh(), $managerUser);
        } catch (RequestNotPending $e) {
            $caught = $e;
        }

        $elapsedMs = (microtime(true) - $start) * 1000;

        // The holder signalled LOCKED before holding for $holdMs more, so a call that
        // returns near-instantly did NOT genuinely contend for the lock — it would mean
        // the row lock isn't real, or this connection saw a stale/uncommitted read
        // instead of blocking on it. A generous margin below $holdMs keeps this from
        // being timing-flaky while still being a meaningful proof of blocking.
        expect($elapsedMs)->toBeGreaterThan($holdMs * 0.5)
            ->and($caught)->not->toBeNull()
            ->and($caught->errorCode())->toBe('request_not_pending')
            ->and($caught->httpStatus())->toBe(409);

        $doneLine = fgets($pipes[1]);
        expect(trim((string) $doneLine))->toBe('DONE');

        $exitCode = proc_close($proc);
        $proc = null;
        expect($exitCode)->toBe(0);

        // The holder process performed the one real approval; this process's concurrent
        // attempt only ever saw it already decided. Exactly one punch, ever.
        expect(AttendanceLog::where('employee_id', $report->id)->count())->toBe(1);

        $fresh = $request->fresh();
        expect($fresh->state)->toBe(RequestState::Approved)
            ->and($fresh->decided_by)->toBe($managerUser->id);
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
        AttendanceLog::where('employee_id', $report->id)->delete();
        Request::whereKey($request->id)->delete();
        Employee::whereIn('id', [$manager->id, $report->id])->delete();
        User::whereIn('id', [$managerUser->id, $reportUser->id])->delete();
        Office::whereKey($office->id)->delete();
    }
});
