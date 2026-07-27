<?php

declare(strict_types=1);

use App\Models\OvertimeDetail;
use App\Models\Request;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

it('rejects non-positive overtime minutes at the database', function (): void {
    $request = Request::factory()->create(['type' => 'overtime']);

    DB::table('overtime_details')->insert([
        'request_id' => $request->id,
        'date' => '2026-07-15',
        'minutes' => 0,
    ]);
})->throws(\Illuminate\Database\QueryException::class);

it('persists an overtime detail keyed by the request id', function (): void {
    $request = Request::factory()->create(['type' => 'overtime']);

    $detail = OvertimeDetail::query()->create([
        'request_id' => $request->id,
        'date' => '2026-07-15',
        'minutes' => 120,
    ]);

    expect($detail->request_id)->toBe($request->id)
        ->and($detail->minutes)->toBe(120)
        ->and($request->fresh()->overtimeDetail->minutes)->toBe(120);
});
