<?php

declare(strict_types=1);

namespace App\Domain\Attendance;

/**
 * Metadata on the row, never a gate. A flagged punch still lands — the Labor Code cares
 * that time was worked, not which network recorded it. See the spec.
 */
enum PunchVerification: string
{
    case Verified = 'verified';
    case Flagged = 'flagged';
}
