<?php

declare(strict_types=1);

namespace App\Exceptions\Domain;

use App\Domain\Requests\RequestState;

/**
 * Thrown when a transition (approve/reject/cancel) targets a request that is no longer
 * actionable. Only reached once the actor is already known to be authorized — an
 * unauthorized actor gets a 404 (RequestAuthority), never this. See docs for the
 * 404-vs-409 split: "can't see it" is 404, "can see it, but it's already decided" is 409.
 *
 * Despite the class name (kept for compatibility with the M6a single-hop error code,
 * `request_not_pending`), this now guards TERMINAL state, not merely "not pending" — a
 * two-hop request in `manager_approved` is still actionable (hop 2) and must NOT throw
 * this; only approved/rejected/cancelled are terminal.
 */
final class RequestNotPending extends DomainException
{
    public function __construct(RequestState $actual)
    {
        parent::__construct("This request is already {$actual->value} and is no longer actionable.");
    }

    public function errorCode(): string
    {
        return 'request_not_pending';
    }

    public function httpStatus(): int
    {
        return 409;
    }
}
