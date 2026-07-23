<?php

declare(strict_types=1);

namespace App\Domain\Attendance;

final readonly class VerificationResult
{
    private function __construct(
        public PunchVerification $status,
        public ?string $reason,
    ) {}

    public static function verified(): self
    {
        return new self(PunchVerification::Verified, null);
    }

    public static function flagged(string $reason): self
    {
        return new self(PunchVerification::Flagged, $reason);
    }
}
