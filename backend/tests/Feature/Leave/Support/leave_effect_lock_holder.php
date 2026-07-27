#!/usr/bin/env php
<?php

declare(strict_types=1);

use App\Actions\Requests\Effects\LeaveEffect;
use App\Domain\Requests\RequestState;
use App\Models\Employee;
use App\Models\Request;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\DB;

/**
 * Standalone helper process for the genuine two-connection concurrency proof in
 * LeaveEffectConcurrencyTest. Deliberately NOT a Pest/PHPUnit test file (no "Test.php"
 * suffix), mirroring tests/Feature/Attendance/Support/approve_lock_holder.php — it boots
 * its own full Laravel application so it opens a genuinely separate PostgreSQL backend
 * connection/session from the one the calling test process uses.
 *
 * Usage: php leave_effect_lock_holder.php <requestId> <employeeId> <approverUserId> <holdMs>
 *
 * Acquires the SAME two locks ApproveRequest + LeaveEffect take for request A (the
 * request row, then — the point of contention — the employee row via
 * Employee::query()->lockForUpdate()), writes "LOCKED\n" the instant BOTH are held (so
 * the parent test can synchronize on that instead of guessing a timing window), holds
 * them for <holdMs> milliseconds, then calls the real, unmodified LeaveEffect to perform
 * the actual debit (re-acquiring the already-held employee lock is a no-op within the
 * same transaction) and writes the same state transition ApproveRequest::execute() would,
 * then commits. The parent process's own concurrent attempt to approve a DIFFERENT
 * request for the SAME employee is expected to block on the employee row lock until this
 * commits, then re-read the now-drained balance.
 */

require __DIR__.'/../../../../vendor/autoload.php';

/** @var Application $app */
$app = require __DIR__.'/../../../../bootstrap/app.php';

$kernel = $app->make(Kernel::class);
$kernel->bootstrap();

[, $requestId, $employeeId, $approverId, $holdMs] = $argv + [null, null, null, null, null];

if ($requestId === null || $employeeId === null || $approverId === null || $holdMs === null) {
    fwrite(STDERR, "usage: leave_effect_lock_holder.php <requestId> <employeeId> <approverUserId> <holdMs>\n");
    exit(2);
}

DB::transaction(function () use ($requestId, $employeeId, $approverId, $holdMs): void {
    $locked = Request::query()->lockForUpdate()->findOrFail($requestId);

    // The same employee-row lock LeaveEffect itself takes for a deducts_balance type —
    // acquired explicitly here so the "LOCKED" signal is provably accurate: by the time
    // the parent reads it, this session genuinely holds the row the second approval must
    // contend for.
    Employee::query()->lockForUpdate()->findOrFail($employeeId);

    fwrite(STDOUT, "LOCKED\n");
    fflush(STDOUT);

    usleep(((int) $holdMs) * 1000);

    // The real, unmodified effect — re-locking the employee row here is a no-op (same
    // transaction, same session already holds it) — this is the thing under test.
    app(LeaveEffect::class)->applyOnApproval($locked, (string) $approverId);

    $locked->update([
        'state' => RequestState::Approved,
        'decided_by' => $approverId,
        'decided_at' => now(),
    ]);
});

fwrite(STDOUT, "DONE\n");
exit(0);
