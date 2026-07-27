<?php

declare(strict_types=1);

namespace App\Domain\Requests;

/**
 * pending → [manager_approved →] approved | rejected | cancelled. No draft — a request is
 * submitted directly. manager_approved is the hop-1 (manager) decision on a two-hop
 * (manager → HR) flow such as leave; a single-hop flow such as attendance adjustment
 * never enters it.
 */
enum RequestState: string
{
    case Pending = 'pending';
    case ManagerApproved = 'manager_approved';
    case Approved = 'approved';
    case Rejected = 'rejected';
    case Cancelled = 'cancelled';
}
