<?php

declare(strict_types=1);

namespace App\Domain\Payroll;

use App\Domain\Pay\SummaryLineKind;

/** One rolled-up earnings line: summed minutes for a (kind, applied_bp, rule_version) triple. */
final readonly class PayrollEarningsLine
{
    public function __construct(
        public SummaryLineKind $kind,
        public int $appliedBp,
        public ?string $ruleVersionId,
        public int $minutes,
    ) {}
}
