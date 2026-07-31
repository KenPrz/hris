<?php

declare(strict_types=1);

namespace App\Domain\Profile;

enum MaritalStatus: string
{
    case Single = 'single';
    case Married = 'married';
    case Widowed = 'widowed';
    case Separated = 'separated';
    case Annulled = 'annulled';
}
