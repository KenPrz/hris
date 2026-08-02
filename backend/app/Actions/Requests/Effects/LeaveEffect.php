<?php

declare(strict_types=1);

namespace App\Actions\Requests\Effects;

use App\Actions\Compute\RecomputeRange;
use App\Domain\Compute\RecomputeTrigger;
use App\Domain\Leave\LeaveBalances;
use App\Domain\Leave\LeaveOverlap;
use App\Domain\Requests\RequestEffect;
use App\Exceptions\Domain\InsufficientLeaveBalance;
use App\Models\Employee;
use App\Models\LeaveLedger;
use App\Models\Request;
use Carbon\CarbonPeriod;
use Illuminate\Support\Facades\DB;

/**
 * The leave effect: debits the ledger on the request's final (HR) approval — never
 * before, and never at all for an event type. Runs inside ApproveRequest's transaction
 * and row lock, so a thrown InsufficientLeaveBalance rolls the whole approval back,
 * exactly like AttendanceAdjustmentEffect's InvalidAdjustmentTarget failure does — the
 * request stays `manager_approved`, no row is written.
 *
 * `ApproveRequest` only locks the REQUEST row, and two different leave requests for the
 * SAME employee are two different rows — that lock alone does not serialize concurrent
 * final-hop approvals of two different requests for one employee. So this locks the
 * EMPLOYEE row first (`Employee::query()->lockForUpdate()->findOrFail(...)`, the same idiom
 * ComputeDailySummary/CreateScheduleAssignment/CreateScheduleOverride/
 * RecordEmploymentChange/ProvisionUser use to serialize per-employee writes) before either
 * of the two checks below. A second concurrent final-hop approval for the same employee
 * blocks on that row lock until the first commits, then re-reads the now-lower balance
 * under `LeaveBalances::forEmployee` — a genuinely fresh read under the lock, not a stale
 * cached column — and correctly throws `InsufficientLeaveBalance` if it would overdraw.
 *
 * The lock is taken for EVERY leave type, not only `deducts_balance` ones. It used to be
 * taken only inside that branch, because a balance was the only thing being protected. The
 * overlap check (M10c) needs it too, and an event type (`deducts_balance=false`, e.g.
 * Maternity/Paternity) can overlap another approved request just as wrongly as a balance
 * type can — it still never touches the ledger, since there is no balance to debit.
 *
 * Both balance and event types get their span recomputed after commit, so Task 9's
 * compute step prices the approved days — enqueued via DB::afterCommit (mirrors
 * CreateHoliday) rather than inside the transaction, since a recompute-enqueue failure
 * must never roll back an already-durable approval.
 */
final class LeaveEffect implements RequestEffect
{
    public function applyOnApproval(Request $request, string $approverUserId): void
    {
        $detail = $request->leaveDetail;
        $type = $detail->leaveType;

        // Serializes concurrent final-hop approvals of DIFFERENT requests for the SAME
        // employee — see the class docblock. Must happen before the overlap and balance
        // checks below, not after.
        //
        // Taken for EVERY leave type, not only balance-deducting ones: an event type
        // (deducts_balance=false, e.g. Maternity) has no balance to overdraw but can still
        // overlap another approved request, and the overlap check below needs this lock to
        // be race-safe just as much as the balance check does.
        Employee::query()->lockForUpdate()->findOrFail($request->employee_id);

        // Nothing enforced this before — not the schema (leave_details has only a primary
        // key, and there are no exclusion constraints anywhere), not submit, not approve. Two
        // overlapping requests therefore both reached final approval, each writing a ledger
        // debit, while the compute path emitted one leave_with_pay line per day: charged
        // twice, paid once.
        LeaveOverlap::assertNoneFor(
            $request->employee_id,
            $detail->start_date->toDateString(),
            $detail->end_date->toDateString(),
            $request->id,
        );

        if ($type->deducts_balance) {
            $balance = LeaveBalances::forEmployee($request->employee)[$type->id] ?? 0;

            if ($detail->amount_minutes > $balance) {
                throw new InsufficientLeaveBalance($type->id, $detail->amount_minutes, $balance);
            }

            LeaveLedger::query()->create([
                'employee_id' => $request->employee_id,
                'leave_type_id' => $type->id,
                'entry_type' => 'debit',
                'minutes' => $detail->amount_minutes,
                'reason' => "Leave request {$request->id} approved",
                'source' => 'leave_taken',
                'request_id' => $request->id,
                'created_by' => $approverUserId,
            ]);
        }

        // Enqueue the recompute over the leave span after commit — both balance and
        // event types (compute prices the days either way).
        DB::afterCommit(function () use ($request, $detail): void {
            $pairs = collect(CarbonPeriod::create($detail->start_date, $detail->end_date))
                ->map(fn ($d): array => ['employee_id' => $request->employee_id, 'date' => $d->toDateString()]);

            RecomputeRange::dispatch(
                $pairs,
                RecomputeTrigger::Leave,
                $request->id,
                "Leave request {$request->id} approved for employee {$request->employee_id}",
            );
        });
    }
}
