<?php

declare(strict_types=1);

use App\Domain\Pay\DayType;
use App\Models\Holiday;
use App\Models\Office;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

it('round-trips day_type and date', function (): void {
    $office = Office::factory()->create();

    $holiday = Holiday::factory()->create([
        'office_id' => $office->id,
        'date' => '2026-12-25',
        'day_type' => DayType::RegularHoliday,
        'name' => 'Christmas Day',
    ]);

    $fresh = $holiday->fresh();

    expect($fresh->day_type)->toBe(DayType::RegularHoliday)
        ->and($fresh->date)->toBeInstanceOf(Illuminate\Support\Carbon::class)
        ->and($fresh->date->toDateString())->toBe('2026-12-25')
        ->and($fresh->name)->toBe('Christmas Day')
        ->and($fresh->office->is($office))->toBeTrue();
});

it('rejects a second holiday on the same office and date', function (): void {
    $office = Office::factory()->create();

    $insert = fn (string $name) => DB::table('holidays')->insert([
        'id' => (string) Str::uuid7(),
        'office_id' => $office->id,
        'date' => '2026-12-25',
        'day_type' => 'regular_holiday',
        'name' => $name,
        'created_at' => now(), 'updated_at' => now(),
    ]);

    expect($insert('Christmas Day'))->toBeTrue();
    expect(fn () => $insert('Duplicate'))->toThrow(QueryException::class);
});

it('rejects day_type values outside the CHECK but accepts a valid one', function (): void {
    $office = Office::factory()->create();

    $insert = fn (string $dayType, string $date) => DB::table('holidays')->insert([
        'id' => (string) Str::uuid7(),
        'office_id' => $office->id,
        'date' => $date,
        'day_type' => $dayType,
        'name' => 'Test Holiday',
        'created_at' => now(), 'updated_at' => now(),
    ]);

    // Valid row first — a CHECK violation aborts the whole Postgres transaction (and
    // RefreshDatabase wraps one), so the refused inserts have to come last.
    expect($insert('regular_holiday', '2026-06-12'))->toBeTrue();

    expect(fn () => $insert('ordinary', '2026-06-13'))->toThrow(QueryException::class);
    expect(fn () => $insert('nonsense', '2026-06-14'))->toThrow(QueryException::class);
});

it('keeps the day_type CHECK in sync with the four non-Ordinary DayType cases', function (): void {
    $nonOrdinary = array_values(array_map(
        fn (DayType $c) => $c->value,
        array_filter(DayType::cases(), fn (DayType $c) => $c !== DayType::Ordinary),
    ));

    expect($nonOrdinary)->toBe([
        'special_working',
        'special_non_working',
        'regular_holiday',
        'double_regular_holiday',
    ]);

    $def = DB::selectOne(
        'SELECT pg_get_constraintdef(oid) AS def FROM pg_constraint WHERE conname = ?',
        ['holidays_day_type_check'],
    );

    expect($def)->not->toBeNull('constraint holidays_day_type_check should exist');

    preg_match_all("/'([^']+)'/", $def->def, $m);
    $checkValues = array_values(array_unique($m[1]));

    $sorted = function (array $v): array {
        sort($v);

        return $v;
    };

    expect($sorted($checkValues))->toBe($sorted($nonOrdinary));
});
