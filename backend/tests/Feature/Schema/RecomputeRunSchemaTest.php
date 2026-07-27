<?php

declare(strict_types=1);

use App\Domain\Compute\RecomputeTrigger;
use App\Models\RecomputeRun;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

/** @return array<string, mixed> */
function recomputeRunAttributes(): array
{
    return [
        'trigger_type' => RecomputeTrigger::PayRule,
        'trigger_id' => (string) Str::uuid7(),
        'reason' => 'pay_rules version 2026-08-01 published',
        'pair_count' => 12,
        'status' => 'queued',
    ];
}

it('stores a run and casts trigger_type and pair_count', function (): void {
    $run = RecomputeRun::create(recomputeRunAttributes());

    $fresh = $run->fresh();

    expect($fresh->trigger_type)->toBe(RecomputeTrigger::PayRule)
        ->and($fresh->pair_count)->toBeInt()
        ->and($fresh->pair_count)->toBe(12)
        ->and($fresh->status)->toBe('queued');
});

it('rejects trigger_type values outside the enum', function (): void {
    $insert = fn (string $triggerType) => DB::table('recompute_runs')->insert([
        'id' => (string) Str::uuid7(),
        'trigger_type' => $triggerType,
        'trigger_id' => null,
        'reason' => 'test',
        'pair_count' => 0,
        'batch_id' => null,
        'status' => 'queued',
        'caused_by' => null,
        'created_at' => now(), 'updated_at' => now(),
    ]);

    expect(fn () => $insert('nonsense'))->toThrow(QueryException::class);
});

it('rejects status values outside the enum', function (): void {
    $attributes = recomputeRunAttributes();
    $attributes['status'] = 'nonsense';

    expect(fn () => RecomputeRun::create($attributes))->toThrow(QueryException::class);
});

it('rejects a negative pair_count', function (): void {
    $attributes = recomputeRunAttributes();
    $attributes['pair_count'] = -1;

    expect(fn () => RecomputeRun::create($attributes))->toThrow(QueryException::class);
});

it('keeps the trigger_type CHECK in sync with RecomputeTrigger::cases()', function (): void {
    // Live-constraint parity — reads the actual CHECK from Postgres so the migration's
    // value list cannot drift from the enum independently (adding a case without widening
    // the CHECK, or vice versa, fails here).
    $def = DB::selectOne(
        "SELECT pg_get_constraintdef(oid) AS def FROM pg_constraint WHERE conname = 'recompute_runs_trigger_type_check'",
    );

    expect($def)->not->toBeNull('constraint recompute_runs_trigger_type_check should exist');

    preg_match_all("/'([^']+)'/", $def->def, $m);
    $checkValues = array_values(array_unique($m[1]));

    $sorted = function (array $v): array {
        sort($v);

        return $v;
    };

    expect($sorted($checkValues))
        ->toBe($sorted(array_map(fn ($c) => $c->value, RecomputeTrigger::cases())));
});
