<?php

declare(strict_types=1);

namespace App\Actions\Profile;

final readonly class DeleteEmployeeIdentificationInput
{
    public function __construct(
        public string $identificationId,
    ) {}
}
