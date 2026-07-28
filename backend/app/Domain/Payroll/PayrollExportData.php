<?php

declare(strict_types=1);

namespace App\Domain\Payroll;

use App\Models\CutoffPeriod;

/** @param  list<PayrollEmployeeExport>  $employees */
final readonly class PayrollExportData
{
    public function __construct(
        public CutoffPeriod $period,
        public array $employees,
    ) {}
}
