<?php

declare(strict_types=1);

namespace App\Domain\Profile;

/**
 * A closed set enforced in PHP, not by a Postgres CHECK — see the M10a spec, decision 4.
 * The column is plain text; this enum is the only definition of what may go in it.
 */
enum Gender: string
{
    case Male = 'male';
    case Female = 'female';
}
