<?php

declare(strict_types=1);

namespace App\Actions\Requests\Effects;

use App\Actions\Compute\RecomputeRange;
use App\Domain\Compute\RecomputeTrigger;
use App\Domain\Leave\LeaveBalances;
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
 * final-hop approvals of two different requests for one employee. So, for a
 * `deducts_balance` type, this locks the EMPLOYEE row first
 * (`Employee::query()->lockForUpdate()->findOrFail(...)`, the same idiom
 * ComputeDailySummary/CreateScheduleAssignment/CreateScheduleOverride/
 * RecordEmploymentChange/ProvisionUser use to serialize per-employee writes) before
 * deriving the balance. A second concurrent final-hop approval for the same employee
 * blocks on that row lock until the first commits its debit, then re-reads the now-lower
 * balance under `LeaveBalances::forEmployee` — a genuinely fresh read under the lock, not
 * a stale cached column — and correctly throws `InsufficientLeaveBalance` if it would
 * overdraw. An event type (`deducts_balance=false`, e.g. Maternity/Paternity) never
 * touches the ledger, or takes this lock, at all — there is no balance to check or debit.
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

        if ($type->deducts_balance) {
            // Serializes concurrent final-hop approvals of DIFFERENT requests for the
            // SAME employee — see the class docblock. Must happen before the balance
            // read below, not after.
            Employee::query()->lockForUpdate()->findOrFail($request->employee_id);

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
