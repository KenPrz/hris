<?php

declare(strict_types=1);

use App\Actions\Requests\Effects\OvertimeEffect;
use App\Domain\Compute\RecomputeTrigger;
use App\Domain\Requests\RequestType;
use App\Models\Employee;
use App\Models\OvertimeDetail;
use App\Models\RecomputeRun;
use App\Models\Request;
use App\Models\User;
use Illuminate\Bus\PendingBatch;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

/*
| Task 4: OvertimeEffect — the recompute half of LeaveEffect with the debit removed.
| Overtime is single-hop (RequestType::requiresHrStep() === false), so the one approval IS
| the final hop: the approved request plus its overtime_details.minutes IS the
| authorization the compute engine reads later (OvertimeAuthorizationLookup, a later task).
| There is no ledger, no balance, no lock — nothing to overdraw — so the effect only
| enqueues a RecomputeRange over the detail's single date via DB::afterCommit.
*/

it('enqueues a recompute over the overtime date and writes no ledger', function (): void {
    Bus::fake();

    $employee = Employee::factory()->create();
    $request = Request::factory()->create([
        'type' => RequestType::Overtime,
        'employee_id' => $employee->id,
        'state' => 'approved',
    ]);
    OvertimeDetail::query()->create([
        'request_id' => $request->id,
        'date' => '2026-07-15',
        'minutes' => 120,
    ]);

    $approverId = $employee->user?->id ?? User::factory()->create()->id;

    (new OvertimeEffect)->applyOnApproval($request->fresh(['overtimeDetail']), $approverId);

    // RecomputeRange dispatches a Bus::batch(), not a plain queued job — mirrors
    // LeaveEffectTest's assertion shape, not a bare Bus::assertDispatched.
    Bus::assertBatched(fn (PendingBatch $batch): bool => $batch->jobs->count() === 1);
    // No leave_ledger / any ledger write — overtime authorization is the request itself.
    expect(DB::table('leave_ledger')->count())->toBe(0);
});

it('enqueues exactly one recompute pair for the employee+date and audits the run under RecomputeTrigger::Overtime', function (): void {
    Bus::fake();

    $employee = Employee::factory()->create();
    $request = Request::factory()->create([
        'type' => RequestType::Overtime,
        'employee_id' => $employee->id,
        'state' => 'approved',
    ]);
    OvertimeDetail::query()->create([
        'request_id' => $request->id,
        'date' => '2026-07-15',
        'minutes' => 120,
    ]);

    $approverId = $employee->user?->id ?? User::factory()->create()->id;

    (new OvertimeEffect)->applyOnApproval($request->fresh(['overtimeDetail']), $approverId);

    $run = RecomputeRun::query()->where('trigger_type', RecomputeTrigger::Overtime)->sole();
    expect($run->trigger_id)->toBe($request->id)
        ->and($run->pair_count)->toBe(1);
});

it('resolves the overtime effect for RequestType::Overtime via the factory', function (): void {
    $effect = app(App\Actions\Requests\RequestEffectFactory::class)->for(RequestType::Overtime);

    expect($effect)->toBeInstanceOf(App\Domain\Requests\RequestEffect::class)
        ->and($effect)->toBeInstanceOf(OvertimeEffect::class);
});
