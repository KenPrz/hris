<?php
declare(strict_types=1);

use App\Domain\Schedule\Weekday;
use App\Models\Office;
use App\Models\ShiftTemplate;
use App\Models\ShiftTemplateDay;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('stores a template with seven weekday rows and casts weekday to the enum', function (): void {
    $office = Office::factory()->create();
    $template = ShiftTemplate::create(['office_id' => $office->id, 'name' => 'Office Mon-Fri']);

    ShiftTemplateDay::create([
        'shift_template_id' => $template->id, 'weekday' => Weekday::Monday,
        'is_rest' => false, 'start_minute' => 480, 'end_minute' => 1080, 'break_minutes' => 60,
    ]);
    ShiftTemplateDay::create([
        'shift_template_id' => $template->id, 'weekday' => Weekday::Saturday, 'is_rest' => true,
        'start_minute' => null, 'end_minute' => null, 'break_minutes' => null,
    ]);

    $mon = $template->days()->where('weekday', Weekday::Monday)->sole();
    expect($mon->weekday)->toBe(Weekday::Monday)
        ->and($mon->weekday->value)->toBe(0)
        ->and($mon->is_rest)->toBeFalse();
});

it('rejects a weekday outside 0..6', function (): void {
    $office = Office::factory()->create();
    $t = ShiftTemplate::create(['office_id' => $office->id, 'name' => 'X']);
    expect(fn () => DB::table('shift_template_days')->insert([
        'id' => Str::uuid7()->toString(), 'shift_template_id' => $t->id,
        'weekday' => 7, 'is_rest' => true, 'created_at' => now(), 'updated_at' => now(),
    ]))->toThrow(QueryException::class);
});

it('rejects a rest row carrying hours, and a working row missing hours (is_rest XOR hours)', function (): void {
    $office = Office::factory()->create();
    $t = ShiftTemplate::create(['office_id' => $office->id, 'name' => 'X']);
    // rest row with hours
    expect(fn () => DB::table('shift_template_days')->insert([
        'id' => Str::uuid7()->toString(), 'shift_template_id' => $t->id, 'weekday' => 1,
        'is_rest' => true, 'start_minute' => 480, 'end_minute' => 1080, 'break_minutes' => 60,
        'created_at' => now(), 'updated_at' => now(),
    ]))->toThrow(QueryException::class);
    // working row missing hours
    expect(fn () => DB::table('shift_template_days')->insert([
        'id' => Str::uuid7()->toString(), 'shift_template_id' => $t->id, 'weekday' => 2,
        'is_rest' => false, 'start_minute' => null, 'end_minute' => null, 'break_minutes' => null,
        'created_at' => now(), 'updated_at' => now(),
    ]))->toThrow(QueryException::class);
});

it('accepts a cross-midnight working row (end_minute up to start+1440) and rejects beyond', function (): void {
    $office = Office::factory()->create();
    $t = ShiftTemplate::create(['office_id' => $office->id, 'name' => 'Night']);
    // 17:00 -> 03:00 == start 1020, end 1620: valid
    ShiftTemplateDay::create(['shift_template_id' => $t->id, 'weekday' => Weekday::Tuesday,
        'is_rest' => false, 'start_minute' => 1020, 'end_minute' => 1620, 'break_minutes' => 0]);
    // end == start (zero length) invalid
    expect(fn () => DB::table('shift_template_days')->insert([
        'id' => Str::uuid7()->toString(), 'shift_template_id' => $t->id, 'weekday' => 3,
        'is_rest' => false, 'start_minute' => 600, 'end_minute' => 600, 'break_minutes' => 0,
        'created_at' => now(), 'updated_at' => now(),
    ]))->toThrow(QueryException::class);
    // break >= span invalid
    expect(fn () => DB::table('shift_template_days')->insert([
        'id' => Str::uuid7()->toString(), 'shift_template_id' => $t->id, 'weekday' => 4,
        'is_rest' => false, 'start_minute' => 480, 'end_minute' => 540, 'break_minutes' => 60,
        'created_at' => now(), 'updated_at' => now(),
    ]))->toThrow(QueryException::class);
    // end beyond start + 1440 invalid: a shift may span at most 24h. start 1020, end 2461 (> 2460).
    expect(fn () => DB::table('shift_template_days')->insert([
        'id' => Str::uuid7()->toString(), 'shift_template_id' => $t->id, 'weekday' => 5,
        'is_rest' => false, 'start_minute' => 1020, 'end_minute' => 2461, 'break_minutes' => 0,
        'created_at' => now(), 'updated_at' => now(),
    ]))->toThrow(QueryException::class);
    expect(true)->toBeTrue();
})->group('schema');

it('cascades day deletion when a template is deleted', function (): void {
    $office = Office::factory()->create();
    $t = ShiftTemplate::create(['office_id' => $office->id, 'name' => 'X']);
    ShiftTemplateDay::create(['shift_template_id' => $t->id, 'weekday' => Weekday::Monday,
        'is_rest' => false, 'start_minute' => 480, 'end_minute' => 1080, 'break_minutes' => 60]);
    $t->delete();
    expect(ShiftTemplateDay::count())->toBe(0);
});
