#!/usr/bin/env php
<?php

declare(strict_types=1);

use App\Domain\Cutoff\CutoffState;
use App\Models\CutoffPeriod;
use App\Models\DailyAttendanceSummary;
use App\Models\Employee;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\DB;

/**
 * Standalone helper process for the genuine two-connection concurrency proof in
 * CloseVsApproveConcurrencyTest. Deliberately NOT a Pest/PHPUnit test file (no "Test.php"
 * suffix), mirroring tests/Feature/Attendance/Support/approve_lock_holder.php and
 * tests/Feature/Leave/Support/leave_effect_lock_holder.php — it boots its own full Laravel
 * application so it opens a genuinely separate PostgreSQL backend connection/session from
 * the one the calling test process uses.
 *
 * Usage: php close_lock_holder.php <employeeId> <periodId> <holdMs>
 *
 * Stands in for a CloseCutoff mid-flight: acquires the SAME per-employee row lock CloseCutoff
 * takes before freezing (Employee::query()->lockForUpdate()), writes "LOCKED\n" the instant
 * the lock is held (so the parent test can synchronize on that instead of guessing a timing
 * window), holds it for <holdMs> milliseconds, then performs the freeze exactly as CloseCutoff
 * would for this one employee — sets that employee's in-period summaries `locked` and the
 * period `closed` — and commits. The parent process's own concurrent ApproveRequest::execute()
 * for a request on a date in this period is expected to block on the employee row lock until
 * this commits, then read the now-closed period and refuse with CutoffLocked.
 */

require __DIR__.'/../../../../vendor/autoload.php';

/** @var Application $app */
$app = require __DIR__.'/../../../../bootstrap/app.php';

$kernel = $app->make(Kernel::class);
$kernel->bootstrap();

[, $employeeId, $periodId, $holdMs] = $argv + [null, null, null, null];

if ($employeeId === null || $periodId === null || $holdMs === null) {
    fwrite(STDERR, "usage: close_lock_holder.php <employeeId> <periodId> <holdMs>\n");
    exit(2);
}

DB::transaction(function () use ($employeeId, $periodId, $holdMs): void {
    // The same per-employee row lock CloseCutoff takes before flipping that employee's
    // summaries — acquired explicitly here so the "LOCKED" signal is provably accurate: by
    // the time the parent reads it, this session genuinely holds the row the concurrent
    // approval must contend for.
    Employee::query()->lockForUpdate()->findOrFail($employeeId);

    fwrite(STDOUT, "LOCKED\n");
    fflush(STDOUT);

    usleep(((int) $holdMs) * 1000);

    $period = CutoffPeriod::query()->findOrFail($periodId);

    // The freeze CloseCutoff performs, for this one employee: lock its in-period summaries,
    // then close the period. Done under the employee lock already held above.
    DailyAttendanceSummary::query()
        ->where('employee_id', $employeeId)
        ->where('office_id', $period->office_id)
        ->whereBetween('date', [$period->start_date->toDateString(), $period->end_date->toDateString()])
        ->update(['status' => 'locked']);

    $period->update([
        'state' => CutoffState::Closed->value,
        'closed_at' => now(),
    ]);
});

fwrite(STDOUT, "DONE\n");
exit(0);
