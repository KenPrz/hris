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
 * Cancels a pending request. Cancellation has its own authority rule — narrower than
 * RequestAuthority — only the requester may withdraw their own request; a manager, HR
 * admin, or system admin who could otherwise decide it may NOT cancel it on the
 * requester's behalf. A non-requester gets 404 (same subject-scope-leak treatment as an
 * unauthorized approver); an already-decided request gets 409.
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

            if (! $locked->isPending()) {
                throw new RequestNotPending($locked->state);
            }

            $locked->update(['state' => RequestState::Cancelled]);

            return $locked;
        });
    }
}
