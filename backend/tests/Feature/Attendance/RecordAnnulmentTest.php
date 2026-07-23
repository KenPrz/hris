<?php

declare(strict_types=1);

use App\Actions\Attendance\RecordAnnulment;
use App\Models\AttendanceAnnulment;
use App\Models\AttendanceLog;
use App\Models\Request;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('records an annulment linking a punch to the approved request', function (): void {
    $log = AttendanceLog::factory()->create();
    $request = Request::factory()->create();

    $annulment = app(RecordAnnulment::class)->execute($log->id, $request->id);

    expect($annulment->attendance_log_id)->toBe($log->id)
        ->and($annulment->request_id)->toBe($request->id)
        ->and(AttendanceAnnulment::count())->toBe(1);
});

it('refuses to annul the same punch twice (the unique backstop)', function (): void {
    $log = AttendanceLog::factory()->create();
    $r1 = Request::factory()->create();
    $r2 = Request::factory()->create();

    app(RecordAnnulment::class)->execute($log->id, $r1->id);

    expect(fn () => app(RecordAnnulment::class)->execute($log->id, $r2->id))
        ->toThrow(Illuminate\Database\QueryException::class);
});
