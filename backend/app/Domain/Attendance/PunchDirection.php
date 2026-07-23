<?php

declare(strict_types=1);

namespace App\Domain\Attendance;

/** Explicit on every punch — the client records what the person meant (see the spec). */
enum PunchDirection: string
{
    case In = 'in';
    case Out = 'out';
}
