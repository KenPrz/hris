<?php

declare(strict_types=1);

namespace App\Actions\Requests;

use App\Domain\Requests\RequestAuthority;
use App\Domain\Requests\RequestEffectResolver;
use App\Domain\Requests\RequestState;
use App\Exceptions\Domain\RequestNotPending;
use App\Models\Request;
use App\Models\User;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;

/**
 * Advances a request ONE HOP at a time and, inside the SAME transaction and under the SAME
 * row lock, dispatches the type effect — but only on the transition INTO `approved`. A
 * single-hop type (e.g. attendance adjustment) goes straight from `pending` to `approved`
 * on its one decision. A two-hop type (e.g. leave) takes two: a manager's decision at
 * `pending` advances the request to `manager_approved` with NO effect and NO `decided_by`
 * — only `manager_decided_by`/`manager_decided_at` — and HR's subsequent decision at
 * `manager_approved` is what fires the effect and writes `decided_by`/`decided_at`. The
 * effect is resolved by type via RequestEffectFactory — for an attendance adjustment, add
 * records a punch, void records an annulment, amend does both. If the effect throws (e.g.
 * InvalidAdjustmentTarget, 422 — the target punch was already annulled by an earlier
 * approval), the whole transaction rolls back: the request stays in its prior state,
 * nothing half-applies.
 *
 * Order of checks matters: lock -> authority (404 if unauthorized, the subject-scope-leak
 * rule — an out-of-scope request or a self-approval attempt must look exactly like a
 * nonexistent one) -> terminal (409 if approved/rejected/cancelled, since an authorized
 * approver CAN see this request; it just isn't actionable anymore — a `manager_approved`
 * request IS still actionable, at hop 2) -> hop transition (effect only on the final hop)
 * -> state write.
 */
final class ApproveRequest
{
    public function __construct(private readonly RequestEffectResolver $effects) {}

    public function execute(Request $request, User $approver): Request
    {
        return DB::transaction(function () use ($request, $approver): Request {
            $locked = Request::query()->lockForUpdate()->findOrFail($request->id);

            if (! RequestAuthority::canDecide($approver, $locked)) {
                throw (new ModelNotFoundException)->setModel(Request::class, [$locked->id]);
            }

            if ($locked->isTerminal()) {
                throw new RequestNotPending($locked->state);
            }

            // Runs under the row lock above: a concurrent second decision on the same hop
            // blocks here until this transaction commits or rolls back, then re-reads
            // state and either lands on the terminal 409 branch or (two-hop, hop 1 raced
            // twice) canDecide's manager_decided_by check, instead of applying the effect
            // or advancing the hop twice.
            $twoHop = $locked->type->requiresHrStep();
            $isFinalHop = ! $twoHop || $locked->state === RequestState::ManagerApproved;

            if ($isFinalHop) {
                $this->effects->for($locked->type)->applyOnApproval($locked, $approver->id);

                $locked->update([
                    'state' => RequestState::Approved,
                    'decided_by' => $approver->id,
                    'decided_at' => now(),
                ]);
            } else {
                // Hop 1 (manager) of a two-hop request: advance to manager_approved, no
                // effect, no decided_by/decided_at yet — those belong to the final hop.
                $locked->update([
                    'state' => RequestState::ManagerApproved,
                    'manager_decided_by' => $approver->id,
                    'manager_decided_at' => now(),
                ]);
            }

            return $locked;
        });
    }
}
