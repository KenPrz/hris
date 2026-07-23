#!/usr/bin/env php
<?php

declare(strict_types=1);

use App\Actions\Attendance\ApplyAttendanceAdjustment;
use App\Domain\Requests\RequestState;
use App\Models\Request;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\DB;

/**
 * Standalone helper process for the genuine two-connection concurrency proof in
 * ApproveRequestConcurrencyTest. Deliberately NOT a Pest/PHPUnit test file (no "Test.php"
 * suffix, so PHPUnit's directory-based discovery never picks it up) — it boots its own
 * full Laravel application, the same way `artisan` does, so it opens a genuinely separate
 * PostgreSQL backend connection/session from the one the calling test process uses. A
 * single PHP process cannot make its own synchronous PDO call block on itself while
 * concurrently unblocking it — that needs a second real process, which is what this is.
 *
 * Usage: php approve_lock_holder.php <requestId> <approverUserId> <holdMs>
 *
 * Acquires the SAME row lock ApproveRequest::execute() takes (`Request::lockForUpdate()`),
 * writes "LOCKED\n" to stdout the instant the lock is held (so the parent test can
 * synchronize on that instead of guessing a timing window), holds it for <holdMs>
 * milliseconds, then applies the exact effect ApproveRequest::execute() would (the same
 * ApplyAttendanceAdjustment call and the same state write), and commits. The parent
 * process's own concurrent attempt to approve the same row is expected to block on the
 * database lock until this commits, then see the row already approved.
 */

require __DIR__.'/../../../../vendor/autoload.php';

/** @var Application $app */
$app = require __DIR__.'/../../../../bootstrap/app.php';

$kernel = $app->make(Kernel::class);
$kernel->bootstrap();

[, $requestId, $approverId, $holdMs] = $argv + [null, null, null, null];

if ($requestId === null || $approverId === null || $holdMs === null) {
    fwrite(STDERR, "usage: approve_lock_holder.php <requestId> <approverUserId> <holdMs>\n");
    exit(2);
}

DB::transaction(function () use ($requestId, $approverId, $holdMs): void {
    $locked = Request::query()->lockForUpdate()->findOrFail($requestId);

    // Signal BEFORE the deliberate hold: the parent blocks reading this line, so by the
    // time it proceeds to attempt its own approve, this transaction provably already
    // holds the row lock — no timing guesswork on either side.
    fwrite(STDOUT, "LOCKED\n");
    fflush(STDOUT);

    usleep(((int) $holdMs) * 1000);

    // The exact effect ApproveRequest::execute() applies, run under the same lock — see
    // that class for the production code this mirrors.
    app(ApplyAttendanceAdjustment::class)->apply($locked, (string) $approverId);

    $locked->update([
        'state' => RequestState::Approved,
        'decided_by' => $approverId,
        'decided_at' => now(),
    ]);
});

fwrite(STDOUT, "DONE\n");
exit(0);
