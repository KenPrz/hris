<?php

declare(strict_types=1);

namespace App\Domain\Requests;

/** pending → approved | rejected | cancelled. No draft — a request is submitted directly. */
enum RequestState: string
{
    case Pending = 'pending';
    case Approved = 'approved';
    case Rejected = 'rejected';
    case Cancelled = 'cancelled';
}
