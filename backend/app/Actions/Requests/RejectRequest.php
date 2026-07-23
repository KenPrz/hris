<?php

declare(strict_types=1);

namespace App\Actions\Requests;

use App\Domain\Requests\RequestAuthority;
use App\Domain\Requests\RequestState;
use App\Exceptions\Domain\RequestNotPending;
use App\Models\Request;
use App\Models\User;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Rejects a pending request. Same authority (404) then pending (409) ordering as
 * ApproveRequest, but there is no ledger effect to dispatch — rejecting never touches the
 * punch or annulment tables.
 *
 * The decision_note required-ness is validated HERE, not in the FormRequest, and only
 * after authority and pending have already passed. Validating it earlier (at the HTTP
 * layer, before route-model-bound authority is checked) would let an out-of-scope prober
 * distinguish "exists but hidden" (400 on an empty body) from "doesn't exist" (404) —
 * exactly the existence leak the 404-not-403 rule exists to prevent. So the order is
 * authority (404) -> pending (409) -> note-validation (400).
 */
final class RejectRequest
{
    public function execute(Request $request, User $approver, ?string $decisionNote): Request
    {
        return DB::transaction(function () use ($request, $approver, $decisionNote): Request {
            $locked = Request::query()->lockForUpdate()->findOrFail($request->id);

            if (! RequestAuthority::canDecide($approver, $locked)) {
                throw (new ModelNotFoundException)->setModel(Request::class, [$locked->id]);
            }

            if (! $locked->isPending()) {
                throw new RequestNotPending($locked->state);
            }

            if ($decisionNote === null || $decisionNote === '') {
                throw ValidationException::withMessages([
                    'decision_note' => 'The decision note is required.',
                ]);
            }

            $locked->update([
                'state' => RequestState::Rejected,
                'decided_by' => $approver->id,
                'decided_at' => now(),
                'decision_note' => $decisionNote,
            ]);

            return $locked;
        });
    }
}
