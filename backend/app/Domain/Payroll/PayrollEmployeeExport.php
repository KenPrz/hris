<?php

declare(strict_types=1);

namespace App\Domain\Payroll;

/**
 * One employee's period rollup. `baseRateCents` is the period-END effective rate (reference
 * only — never multiplied out); `baseRateSegments` lists the distinct effective rates that
 * applied to in-period days (one element in the common constant-rate case).
 *
 * @param  list<PayrollEarningsLine>  $lines
 * @param  list<array{effective_from: string, base_rate_cents: int}>  $baseRateSegments
 */
final readonly class PayrollEmployeeExport
{
    public function __construct(
        public string $employeeId,
        public string $employeeNo,
        public ?int $baseRateCents,
        public array $baseRateSegments,
        public int $workedMinutes,
        public int $lateMinutes,
        public int $undertimeMinutes,
        public int $unpaidOvertimeMinutes,
        public array $lines,
        public bool $hasIncompleteDays,
    ) {}
}
