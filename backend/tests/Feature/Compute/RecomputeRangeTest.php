<?php

declare(strict_types=1);

use App\Actions\Compute\RecomputeRange;
use App\Domain\Compute\RecomputeTrigger;
use App\Jobs\RecomputeDay;
use App\Models\RecomputeRun;
use App\Models\User;
use Illuminate\Bus\PendingBatch;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

/*
| Task 4: RecomputeRange — the dispatch mechanism. Dedups an iterable of
| (employee_id, date) pairs, audits the run as a recompute_runs row, and dispatches a
| Bus::batch of RecomputeDay jobs. Bus::fake() is used for the dedup/audit assertions
| (it never runs a job, so `then()` never fires — the run stays `queued`); the status
| lifecycle test at the bottom leaves Bus unfaked so the batch runs for real against the
| test suite's `QUEUE_CONNECTION=sync`, letting `then()` fire in-process and giving a
| genuine assertion that `status` reaches `completed`.
*/

it('dedups pairs, dispatches a Bus::batch of RecomputeDay, and audits the run', function (): void {
    Bus::fake();

    $holidayId = (string) Str::uuid7();

    $run = RecomputeRange::dispatch(
        [
            ['employee_id' => 'e1', 'date' => '2026-08-21'],
            ['employee_id' => 'e1', 'date' => '2026-08-21'], // duplicate of the pair above
            ['employee_id' => 'e2', 'date' => '2026-08-21'],
        ],
        RecomputeTrigger::Holiday,
        $holidayId,
        'Holiday created for Manila on 2026-08-21',
    );

    Bus::assertBatched(fn (PendingBatch $batch): bool => $batch->jobs->count() === 2);

    expect($run)->not->toBeNull()
        ->and($run->pair_count)->toBe(2)
        ->and($run->trigger_type)->toBe(RecomputeTrigger::Holiday)
        ->and($run->trigger_id)->toBe($holidayId)
        ->and($run->reason)->toBe('Holiday created for Manila on 2026-08-21')
        ->and($run->status)->toBe('queued'); // Bus::fake() never runs the batch's then()

    $this->assertDatabaseCount('recompute_runs', 1);
});

it('carries the right (employeeId, date) on each batched RecomputeDay job', function (): void {
    Bus::fake();

    RecomputeRange::dispatch(
        [
            ['employee_id' => 'e1', 'date' => '2026-08-21'],
            ['employee_id' => 'e2', 'date' => '2026-08-22'],
        ],
        RecomputeTrigger::PayRule,
        null,
        'pay_rules version published',
    );

    Bus::assertBatched(function (PendingBatch $batch): bool {
        $pairs = collect($batch->jobs)
            ->map(fn (RecomputeDay $job): array => [$job->employeeId, $job->date])
            ->all();

        return $batch->jobs->count() === 2
            && in_array(['e1', '2026-08-21'], $pairs, true)
            && in_array(['e2', '2026-08-22'], $pairs, true);
    });
});

it('is a clean no-op for zero (deduped) pairs: nothing dispatched, no run created', function (): void {
    Bus::fake();

    $run = RecomputeRange::dispatch([], RecomputeTrigger::Holiday, (string) Str::uuid7(), 'r');

    Bus::assertNothingBatched();
    expect($run)->toBeNull();
    $this->assertDatabaseCount('recompute_runs', 0);
});

it('is also a clean no-op when every pair is a duplicate of another', function (): void {
    Bus::fake();

    $run = RecomputeRange::dispatch(
        [
            ['employee_id' => 'e1', 'date' => '2026-08-21'],
            ['employee_id' => 'e1', 'date' => '2026-08-21'],
        ],
        RecomputeTrigger::Holiday,
        (string) Str::uuid7(),
        'r',
    );

    Bus::assertBatched(fn (PendingBatch $batch): bool => $batch->jobs->count() === 1);
    expect($run)->not->toBeNull()->and($run->pair_count)->toBe(1);
});

it('records trigger_type/trigger_id/reason/caused_by verbatim on the run', function (): void {
    Bus::fake();

    $causedBy = User::factory()->create();
    $shiftTemplateId = (string) Str::uuid7();

    $run = RecomputeRange::dispatch(
        [['employee_id' => 'e1', 'date' => '2026-08-21']],
        RecomputeTrigger::ShiftTemplate,
        $shiftTemplateId,
        'Shift template Standard changed',
        $causedBy->id,
    );

    expect($run->trigger_type)->toBe(RecomputeTrigger::ShiftTemplate)
        ->and($run->trigger_id)->toBe($shiftTemplateId)
        ->and($run->reason)->toBe('Shift template Standard changed')
        ->and($run->caused_by)->toBe($causedBy->id);
});

it('runs the batch for real (sync queue, Bus unfaked) and reaches status completed via then()', function (): void {
    // Deliberately no Bus::fake() here: the test suite's QUEUE_CONNECTION is `sync`
    // (phpunit.xml), so Bus::batch(...)->dispatch() runs every job in-process and fires
    // the batch's then() callback before dispatch() returns — a real lifecycle
    // assertion, not a simulated one. The employee id does not need to resolve to a real
    // Employee: RecomputeDay's handle() no-ops (and succeeds) when Employee::find()
    // comes back null, which is enough for the batch to finish successfully.
    $run = RecomputeRange::dispatch(
        [['employee_id' => (string) Str::uuid7(), 'date' => '2026-08-21']],
        RecomputeTrigger::Holiday,
        (string) Str::uuid7(),
        'real batch run',
    );

    expect($run)->not->toBeNull()
        ->and($run->batch_id)->not->toBeNull()
        ->and($run->fresh()->status)->toBe('completed');
});
