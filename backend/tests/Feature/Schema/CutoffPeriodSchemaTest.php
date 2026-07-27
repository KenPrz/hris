<?php

declare(strict_types=1);

use App\Domain\Cutoff\CutoffState;
use App\Models\CutoffPeriod;
use App\Models\Office;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

it('pins the state CHECK to CutoffState::cases()', function (): void {
    $def = DB::selectOne("SELECT pg_get_constraintdef(oid) AS def FROM pg_constraint WHERE conname = 'cutoff_periods_state_check'")->def;
    foreach (CutoffState::cases() as $case) {
        expect($def)->toContain("'{$case->value}'");
    }
});

it('rejects an unknown state at the database', function (): void {
    $office = Office::factory()->create();
    expect(fn () => DB::table('cutoff_periods')->insert([
        'id' => (string) Illuminate\Support\Str::uuid7(),
        'office_id' => $office->id, 'start_date' => '2026-07-01', 'end_date' => '2026-07-15',
        'state' => 'nonsense', 'created_at' => now(), 'updated_at' => now(),
    ]))->toThrow(QueryException::class);
});

it('rejects end_date before start_date', function (): void {
    $office = Office::factory()->create();
    expect(fn () => DB::table('cutoff_periods')->insert([
        'id' => (string) Illuminate\Support\Str::uuid7(),
        'office_id' => $office->id, 'start_date' => '2026-07-15', 'end_date' => '2026-07-01',
        'state' => 'open', 'created_at' => now(), 'updated_at' => now(),
    ]))->toThrow(QueryException::class);
});

it('enforces one period per (office, start_date)', function (): void {
    $office = Office::factory()->create();
    CutoffPeriod::factory()->create(['office_id' => $office->id, 'start_date' => '2026-07-01', 'end_date' => '2026-07-15']);
    expect(fn () => CutoffPeriod::factory()->create(['office_id' => $office->id, 'start_date' => '2026-07-01', 'end_date' => '2026-07-15']))
        ->toThrow(QueryException::class);
});
