<?php

declare(strict_types=1);

namespace App\Domain\Attendance;

/** What an attendance-adjustment request does to the append-only punch ledger. */
enum AdjustmentOperation: string
{
    case Add = 'add';
    case Void = 'void';
    case Amend = 'amend';
}
