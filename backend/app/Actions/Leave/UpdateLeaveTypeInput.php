<?php

declare(strict_types=1);

namespace App\Actions\Leave;

final readonly class UpdateLeaveTypeInput
{
    public function __construct(
        public string $name,
        public ?string $code,
        public bool $isPaid,
        public bool $requiresAttachment,
        public bool $deductsBalance,
        public bool $isCashConvertible,
        public ?int $maxCarryoverMinutes,
        public bool $isActive,
    ) {}
}
