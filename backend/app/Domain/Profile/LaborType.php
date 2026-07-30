<?php

declare(strict_types=1);

namespace App\Domain\Profile;

/** Lives on employment_records, not the profile: a transfer can change it. */
enum LaborType: string
{
    case Direct = 'direct';
    case Indirect = 'indirect';
}
