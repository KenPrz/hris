<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;

/*
| The append-only invariant, proven two ways at once. attendance_logs is the raw record
| shown a DOLE inspector; a correction is a NEW row, never an edit. Two things have to hold
| for that to be true and stay true:
|
|   1. No HTTP surface can mutate a punch — there is no PATCH/PUT/DELETE route anywhere
|      under `attendance`, so the ledger cannot be edited or erased through the API.
|   2. The single writer only ever appends — RecordPunch (the one class the arch guard in
|      tests/Arch/ConventionsTest.php permits to touch the table) calls create() and nothing
|      that updates, deletes, or saves-over an existing row.
|
| The arch guard already proves RecordPunch is the ONLY writer; this test proves that the
| one permitted writer, and the route surface as a whole, are append-only. Together they
| close the loop: nothing else writes, and the thing that writes only appends.
*/

it('registers no route that mutates attendance', function (): void {
    $mutating = ['PUT', 'PATCH', 'DELETE'];

    $offenders = collect(Route::getRoutes()->getRoutes())
        ->filter(fn ($route): bool => str_contains($route->uri(), 'attendance'))
        ->filter(fn ($route): bool => array_intersect($route->methods(), $mutating) !== [])
        ->map(fn ($route): string => implode('|', $route->methods()).' '.$route->uri())
        ->values()
        ->all();

    expect($offenders)->toBe(
        [],
        'A route mutates attendance_logs through the API; the ledger must be append-only: '.implode(', ', $offenders),
    );
});

it('exposes attendance routes, so the guard above is not vacuous', function (): void {
    // Guard against the test passing simply because no attendance route exists at all: the
    // append-only assertion is only meaningful if there ARE attendance routes to constrain.
    $attendanceRoutes = collect(Route::getRoutes()->getRoutes())
        ->filter(fn ($route): bool => str_contains($route->uri(), 'attendance'))
        ->map(fn ($route): string => implode('|', $route->methods()).' '.$route->uri())
        ->values()
        ->all();

    // The self-service punch, the manual HR punch, and the two read paths — all
    // append-only (POST/GET), none mutating.
    expect($attendanceRoutes)->not->toBe([])
        ->and(collect($attendanceRoutes)->every(
            fn (string $r): bool => str_starts_with($r, 'GET') || str_starts_with($r, 'POST') || str_starts_with($r, 'HEAD'),
        ))->toBeTrue();
});

it('has RecordPunch, the sole writer, only ever append', function (): void {
    // RecordPunch is the one class the arch guard lets write attendance_logs. Prove that the
    // one permitted writer appends and never mutates: it calls create(), and contains no
    // update/delete/save form that would rewrite an existing row.
    $source = file_get_contents(base_path('app/Actions/Attendance/RecordPunch.php'));

    expect($source)->toContain('->create(');

    foreach (['->update(', '->delete(', '->save(', 'updateOrCreate(', 'upsert('] as $mutation) {
        expect($source)->not->toContain($mutation);
    }
});
