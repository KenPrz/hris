<?php

declare(strict_types=1);

namespace App\Actions\Requests;

use App\Domain\Requests\RequestState;
use App\Exceptions\Domain\RequestNotPending;
use App\Models\Request;
use App\Models\User;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;

/**
 * Cancels a not-yet-terminal request — `pending` OR `manager_approved`. Cancellation has
 * its own authority rule — narrower than RequestAuthority — only the requester may
 * withdraw their own request; a manager, HR admin, or system admin who could otherwise
 * decide it may NOT cancel it on the requester's behalf. A non-requester gets 404 (same
 * subject-scope-leak treatment as an unauthorized approver); a TERMINAL request (approved/
 * rejected/cancelled) gets 409 — same `isTerminal()` guard ApproveRequest/RejectRequest
 * already use, so a two-hop (leave) request still awaiting HR at hop 2 remains
 * withdrawable, not stuck once a manager has signed off on it.
 */
final class CancelRequest
{
    public function execute(Request $request, User $actor): Request
    {
        return DB::transaction(function () use ($request, $actor): Request {
            $locked = Request::query()->lockForUpdate()->findOrFail($request->id);

            if ($actor->employee?->id !== $locked->employee_id) {
                throw (new ModelNotFoundException)->setModel(Request::class, [$locked->id]);
            }

            if ($locked->isTerminal()) {
                throw new RequestNotPending($locked->state);
            }

            $locked->update(['state' => RequestState::Cancelled]);

            return $locked;
        });
    }
}
