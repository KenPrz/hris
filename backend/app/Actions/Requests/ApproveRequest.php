<?php

declare(strict_types=1);

namespace App\Actions\Requests;

use App\Actions\Attendance\ApplyAttendanceAdjustment;
use App\Domain\Requests\RequestAuthority;
use App\Domain\Requests\RequestState;
use App\Exceptions\Domain\RequestNotPending;
use App\Models\Request;
use App\Models\User;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;

/**
 * Approves a pending request and, inside the SAME transaction and under the SAME row
 * lock, dispatches the type effect. For an attendance adjustment that is
 * ApplyAttendanceAdjustment — add records a punch, void records an annulment, amend does
 * both. If the effect throws (e.g. InvalidAdjustmentTarget, 422 — the target punch was
 * already annulled by an earlier approval), the whole transaction rolls back: the request
 * stays pending, nothing half-applies.
 *
 * Order of checks matters: lock -> authority (404 if unauthorized, the subject-scope-leak
 * rule — an out-of-scope request or a self-approval attempt must look exactly like a
 * nonexistent one) -> pending (409 if already decided, since an authorized approver CAN
 * see this request; it just isn't actionable) -> effect -> state write.
 */
final class ApproveRequest
{
    public function __construct(private readonly ApplyAttendanceAdjustment $applyAttendanceAdjustment) {}

    public function execute(Request $request, User $approver): Request
    {
        return DB::transaction(function () use ($request, $approver): Request {
            $locked = Request::query()->lockForUpdate()->findOrFail($request->id);

            if (! RequestAuthority::canDecide($approver, $locked)) {
                throw (new ModelNotFoundException)->setModel(Request::class, [$locked->id]);
            }

            if (! $locked->isPending()) {
                throw new RequestNotPending($locked->state);
            }

            // Runs under the row lock above: a concurrent second approve blocks here until
            // this transaction commits or rolls back, then re-reads state as no longer
            // pending and takes the 409 branch instead of applying the effect twice.
            $this->applyAttendanceAdjustment->apply($locked, $approver->id);

            $locked->update([
                'state' => RequestState::Approved,
                'decided_by' => $approver->id,
                'decided_at' => now(),
            ]);

            return $locked;
        });
    }
}
