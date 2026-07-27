<?php

declare(strict_types=1);

namespace App\Domain\Cutoff;

/** The lifecycle of a cutoff period. Widens only if a mid-state is ever needed. */
enum CutoffState: string
{
    case Open = 'open';
    case Closed = 'closed';
}
