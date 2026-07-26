<?php

declare(strict_types=1);

namespace App\Domain\Requests;

use App\Models\Request;

/**
 * The side effect an approved request applies, inside ApproveRequest's transaction and row
 * lock. One implementation per RequestType — attendance adjustment today; leave and
 * overtime add their own in M6b/M6c without touching the approval path. A framework-
 * agnostic contract (Domain); the implementations live in the Actions layer, where writing
 * to models belongs.
 */
interface RequestEffect
{
    public function applyOnApproval(Request $request, string $approverUserId): void;
}
