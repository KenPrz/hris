#!/usr/bin/env php
<?php

declare(strict_types=1);

use App\Actions\Attendance\ApplyAttendanceAdjustment;
use App\Domain\Requests\RequestState;
use App\Models\AttendanceLog;
use App\Models\Request;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\DB;

/**
 * Standalone helper process for ApplyAttendanceAdjustmentConcurrencyTest — the same
 * genuine-second-process pattern as approve_lock_holder.php, but proving a DIFFERENT seam:
 * two DISTINCT requests that both target the SAME attendance_logs row lock two DIFFERENT
 * `requests` rows, so ApproveRequest's request-row lock never serializes them against each
 * other. What must serialize them is assertAnnullable's lock on the shared TARGET log row.
 *
 * No "Test.php" suffix, so PHPUnit's directory discovery never picks it up. Boots its own
 * full Laravel application (a genuinely separate PostgreSQL backend connection from the
 * calling test process — a single PHP process cannot synchronously block on and unblock
 * its own PDO call).
 *
 * Usage: php annul_lock_holder.php <firstRequestId> <approverUserId> <holdMs>
 *
 * Locks the first void request's row, then locks its target attendance_logs row (the same
 * lock assertAnnullable now takes), signals "LOCKED\n" the instant that target-row lock is
 * held — this is the exact lock the parent's concurrent approval of the SECOND void request
 * must block on — holds it for <holdMs> ms, then applies the real effect (records the
 * annulment) and commits.
 */

require __DIR__.'/../../../../vendor/autoload.php';

/** @var Application $app */
$app = require __DIR__.'/../../../../bootstrap/app.php';

$kernel = $app->make(Kernel::class);
$kernel->bootstrap();

[, $requestId, $approverId, $holdMs] = $argv + [null, null, null, null];

if ($requestId === null || $approverId === null || $holdMs === null) {
    fwrite(STDERR, "usage: annul_lock_holder.php <firstRequestId> <approverUserId> <holdMs>\n");
    exit(2);
}

DB::transaction(function () use ($requestId, $approverId, $holdMs): void {
    $locked = Request::query()->lockForUpdate()->findOrFail($requestId);

    /** @var \App\Models\AttendanceAdjustmentDetail $detail */
    $detail = $locked->attendanceAdjustmentDetail()->firstOrFail();

    // Manually take the same row lock assertAnnullable takes on the target log, so we can
    // signal the instant it is held rather than guessing a timing window. Re-locking the
    // same row from within ApplyAttendanceAdjustment::apply() below (same transaction, same
    // connection) is then a no-op — Postgres row locks don't self-block within one session.
    AttendanceLog::query()->lockForUpdate()->findOrFail($detail->target_log_id);

    fwrite(STDOUT, "LOCKED\n");
    fflush(STDOUT);

    usleep(((int) $holdMs) * 1000);

    // The exact effect ApproveRequest::execute() applies, run under the same locks — see
    // ApplyAttendanceAdjustment for the production code this mirrors.
    app(ApplyAttendanceAdjustment::class)->apply($locked, (string) $approverId);

    $locked->update([
        'state' => RequestState::Approved,
        'decided_by' => $approverId,
        'decided_at' => now(),
    ]);
});

fwrite(STDOUT, "DONE\n");
exit(0);
