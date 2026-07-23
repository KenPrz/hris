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

/**
 * Rejects a pending request. Same authority (404) then pending (409) ordering as
 * ApproveRequest, but there is no ledger effect to dispatch — rejecting never touches the
 * punch or annulment tables.
 */
final class RejectRequest
{
    public function execute(Request $request, User $approver, string $decisionNote): Request
    {
        return DB::transaction(function () use ($request, $approver, $decisionNote): Request {
            $locked = Request::query()->lockForUpdate()->findOrFail($request->id);

            if (! RequestAuthority::canDecide($approver, $locked)) {
                throw (new ModelNotFoundException)->setModel(Request::class, [$locked->id]);
            }

            if (! $locked->isPending()) {
                throw new RequestNotPending($locked->state);
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
