<?php

declare(strict_types=1);

use App\Actions\Requests\ApproveRequest;
use App\Domain\Leave\LeaveBalances;
use App\Domain\Requests\RequestState;
use App\Domain\Requests\RequestType;
use App\Domain\Schedule\Weekday;
use App\Exceptions\Domain\InsufficientLeaveBalance;
use App\Models\Employee;
use App\Models\LeaveDetail;
use App\Models\LeaveLedger;
use App\Models\LeaveType;
use App\Models\Office;
use App\Models\RecomputeRun;
use App\Models\Request;
use App\Models\ShiftTemplate;
use App\Models\ShiftTemplateDay;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/*
| Task 8 review Critical: ApproveRequest locks the REQUEST row (Request::lockForUpdate()),
| not the employee. Two DIFFERENT leave requests for the SAME employee, both reaching the
| final HR hop concurrently, take two DIFFERENT request-row locks and never serialize on
| that lock alone — both would call LeaveBalances::forEmployee, both read the same
| pre-debit balance, both pass amount_minutes > balance, and both could commit a debit,
| overdrawing the employee's balance below zero. LeaveEffect now locks the EMPLOYEE row
| (Employee::query()->lockForUpdate()) before deriving the balance, which is what actually
| has to serialize this.
|
| A single-process/sequential test cannot prove this: it passes identically whether or not
| the lock exists, because nothing is ever held open while a second attempt is in flight.
| This is the genuine two-connection proof, mirroring
| tests/Feature/Attendance/ApproveRequestConcurrencyTest.php: a real, separate OS process
| (a second Postgres backend session) holds LeaveEffect's employee lock open while
| debiting request A; THIS process's own concurrent, real, unmodified
| ApproveRequest::execute() for a DIFFERENT request (B — same employee, same leave type,
| the SAME full-balance amount, so only one of the two can ever be granted) must
| genuinely block on that lock, then — once the holder commits — re-read the now-drained
| balance and correctly throw InsufficientLeaveBalance, never overdrawing the ledger.
|
| Deliberately NOT uses(RefreshDatabase::class): see the attendance file's header — a
| second, genuinely separate database connection can only see committed rows, so this
| file commits its fixtures for real and cleans them up by hand in a finally block.
|
| The office below carries a real Mon-Fri shift template (not just `ip_allowlist: null`)
| because LeaveEffect's afterCommit recompute is NOT gated on "does a summary already
| exist" (unlike CreateHoliday's AffectedSummaries) — it always dispatches over the
| approved span, and the test suite's QUEUE_CONNECTION=sync means the holder's successful
| debit for request A runs ComputeDailySummary for real, in-process, immediately after its
| commit. An office with no default_shift_template_id would make that throw
| OfficeHasNoDefaultTemplate and crash the holder process before it ever reaches "DONE".
*/

it('genuinely serializes two concurrent final-hop leave approvals for the SAME employee through the employee lock, never overdrawing the balance', function (): void {
    $office = Office::factory()->create(['ip_allowlist' => null]);

    $template = ShiftTemplate::create(['office_id' => $office->id, 'name' => 'Office']);
    foreach (Weekday::cases() as $wd) {
        $rest = in_array($wd, [Weekday::Saturday, Weekday::Sunday], true);
        ShiftTemplateDay::create([
            'shift_template_id' => $template->id, 'weekday' => $wd, 'is_rest' => $rest,
            'start_minute' => $rest ? null : 480, 'end_minute' => $rest ? null : 1080,
            'break_minutes' => $rest ? null : 60,
        ]);
    }
    $office->update(['default_shift_template_id' => $template->id]);

    $hrUser = User::factory()->create();
    $hrEmployee = Employee::factory()->for($hrUser)->create(['current_office_id' => $office->id]);
    $hrUser->hrAdminOffices()->attach($office->id);

    $managerUser = User::factory()->create();
    $manager = Employee::factory()->for($managerUser)->create(['current_office_id' => $office->id]);

    $reportUser = User::factory()->create();
    $report = Employee::factory()->for($reportUser)->create([
        'current_office_id' => $office->id,
        'current_reports_to_id' => $manager->id,
    ]);

    $leaveType = LeaveType::factory()->for($office, 'office')->create(['deducts_balance' => true]);

    // Exactly a 2-day balance — enough for ONE of the two requests below, never both.
    LeaveLedger::factory()->for($report, 'employee')->create([
        'leave_type_id' => $leaveType->id,
        'entry_type' => 'credit',
        'minutes' => 960,
    ]);

    $requestA = Request::factory()->for($report)->create([
        'type' => RequestType::Leave,
        'state' => RequestState::ManagerApproved,
        'manager_decided_by' => $managerUser->id,
        'manager_decided_at' => now(),
    ]);
    LeaveDetail::factory()->for($requestA)->create([
        'leave_type_id' => $leaveType->id,
        'start_date' => '2026-08-03', // Monday
        'end_date' => '2026-08-04',   // Tuesday
        'day_part' => 'full',
        'amount_minutes' => 960,
    ]);

    $requestB = Request::factory()->for($report)->create([
        'type' => RequestType::Leave,
        'state' => RequestState::ManagerApproved,
        'manager_decided_by' => $managerUser->id,
        'manager_decided_at' => now(),
    ]);
    LeaveDetail::factory()->for($requestB)->create([
        'leave_type_id' => $leaveType->id,
        'start_date' => '2026-08-10', // Monday
        'end_date' => '2026-08-11',   // Tuesday
        'day_part' => 'full',
        'amount_minutes' => 960,
    ]);

    $holderScript = __DIR__.'/Support/leave_effect_lock_holder.php';
    $holdMs = 500;

    $cleanup = function () use ($requestA, $requestB, $leaveType, $office, $template, $manager, $report, $hrEmployee, $managerUser, $reportUser, $hrUser): void {
        LeaveLedger::where('employee_id', $report->id)->delete();
        // Real (unfaked) Bus, sync queue — the holder's successful debit really dispatched
        // a recompute; this row is not wrapped in any transaction the test can roll back.
        RecomputeRun::where('trigger_id', $requestA->id)->delete();
        LeaveDetail::whereIn('request_id', [$requestA->id, $requestB->id])->delete();
        Request::whereIn('id', [$requestA->id, $requestB->id])->delete();
        LeaveType::whereKey($leaveType->id)->delete();
        $office->update(['default_shift_template_id' => null]);
        ShiftTemplateDay::where('shift_template_id', $template->id)->delete();
        ShiftTemplate::whereKey($template->id)->delete();
        Employee::whereIn('id', [$manager->id, $report->id, $hrEmployee->id])->delete();
        User::whereIn('id', [$managerUser->id, $reportUser->id, $hrUser->id])->delete();
        Office::whereKey($office->id)->delete();
    };

    $proc = proc_open(
        ['php', $holderScript, $requestA->id, $report->id, $hrUser->id, (string) $holdMs],
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
            // The real, unmodified action — this is what the employee lock must serialize.
            app(ApproveRequest::class)->execute($requestB->fresh(), $hrUser);
        } catch (InsufficientLeaveBalance $e) {
            $caught = $e;
        }

        $elapsedMs = (microtime(true) - $start) * 1000;

        // The holder signalled LOCKED before holding for $holdMs more, so a call that
        // returns near-instantly did NOT genuinely contend for the employee lock — it
        // would mean the lock isn't real, or this connection saw a stale read instead of
        // blocking on it.
        expect($elapsedMs)->toBeGreaterThan($holdMs * 0.5)
            ->and($caught)->not->toBeNull()
            ->and($caught->errorCode())->toBe('insufficient_leave_balance')
            ->and($caught->httpStatus())->toBe(422);

        $doneLine = fgets($pipes[1]);
        expect(trim((string) $doneLine))->toBe('DONE');

        $exitCode = proc_close($proc);
        $proc = null;
        expect($exitCode)->toBe(0);

        // Exactly one debit, ever — request A's. Request B's concurrent attempt never
        // wrote a row, and the balance never went negative.
        $debits = LeaveLedger::where('employee_id', $report->id)->where('entry_type', 'debit')->get();
        expect($debits)->toHaveCount(1)
            ->and($debits->first()->request_id)->toBe($requestA->id)
            ->and($debits->first()->minutes)->toBe(960);

        expect(LeaveBalances::forEmployee($report->fresh())[$leaveType->id])->toBe(0);

        $freshA = $requestA->fresh();
        $freshB = $requestB->fresh();

        expect($freshA->state)->toBe(RequestState::Approved)
            ->and($freshA->decided_by)->toBe($hrUser->id)
            ->and($freshB->state)->toBe(RequestState::ManagerApproved)
            ->and($freshB->decided_by)->toBeNull()
            ->and($freshB->decided_at)->toBeNull();
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
