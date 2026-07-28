#!/usr/bin/env php
<?php

declare(strict_types=1);

use App\Actions\Compute\ComputeDailySummary;
use App\Models\Employee;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\DB;

/**
 * Standalone helper process for the genuine two-connection concurrency proof in
 * CloseVsRecomputeConcurrencyTest. Deliberately NOT a Pest/PHPUnit test file (no "Test.php"
 * suffix), mirroring tests/Feature/Cutoff/Support/close_lock_holder.php and
 * tests/Feature/Attendance/Support/approve_lock_holder.php — it boots its own full Laravel
 * application so it opens a genuinely separate PostgreSQL backend connection/session from
 * the one the calling test process uses.
 *
 * Usage: php recompute_lock_holder.php <employeeId> <date> <holdMs>
 *
 * Stands in for a ComputeDailySummary recompute mid-flight: acquires the SAME per-employee
 * row lock ComputeDailySummary::execute takes at the top of its transaction
 * (Employee::query()->lockForUpdate()), writes "LOCKED\n" the instant the lock is held (so
 * the parent test can synchronize on that instead of guessing a timing window), holds it for
 * <holdMs> milliseconds, then runs a real ComputeDailySummary::execute for this employee-day
 * (the recompute itself, which re-takes the same lock reentrantly within this transaction)
 * and commits. The parent process's own concurrent CloseCutoff::execute() for the period is
 * expected to block on the employee row lock until this commits, then freeze the recompute's
 * result — leaving the summary `locked`, not the recompute's unlocked `computed`.
 */

require __DIR__.'/../../../../vendor/autoload.php';

/** @var Application $app */
$app = require __DIR__.'/../../../../bootstrap/app.php';

$kernel = $app->make(Kernel::class);
$kernel->bootstrap();

[, $employeeId, $date, $holdMs] = $argv + [null, null, null, null];

if ($employeeId === null || $date === null || $holdMs === null) {
    fwrite(STDERR, "usage: recompute_lock_holder.php <employeeId> <date> <holdMs>\n");
    exit(2);
}

DB::transaction(function () use ($employeeId, $date, $holdMs): void {
    // The same per-employee row lock ComputeDailySummary::execute takes before it
    // delete-then-inserts the summary — acquired explicitly here so the "LOCKED" signal is
    // provably accurate: by the time the parent reads it, this session genuinely holds the
    // row the concurrent close must contend for.
    $employee = Employee::query()->lockForUpdate()->findOrFail($employeeId);

    fwrite(STDOUT, "LOCKED\n");
    fflush(STDOUT);

    usleep(((int) $holdMs) * 1000);

    // The recompute itself, under the lock already held above — the period is still open at
    // this point (the parent's close blocks on our lock), so this writes a fresh `computed`
    // row. The close then serializes behind our commit and freezes it to `locked`.
    app(ComputeDailySummary::class)->execute($employee, $date);
});

fwrite(STDOUT, "DONE\n");
exit(0);
