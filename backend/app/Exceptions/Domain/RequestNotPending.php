<?php

declare(strict_types=1);

namespace App\Exceptions\Domain;

use App\Domain\Requests\RequestState;

/**
 * Thrown when a transition (approve/reject/cancel) targets a request that is no longer
 * pending. Only reached once the actor is already known to be authorized — an
 * unauthorized actor gets a 404 (RequestAuthority), never this. See docs for the
 * 404-vs-409 split: "can't see it" is 404, "can see it, but it's already decided" is 409.
 */
final class RequestNotPending extends DomainException
{
    public function __construct(RequestState $actual)
    {
        parent::__construct("This request is already {$actual->value}, not pending.");
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
