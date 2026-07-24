<?php
declare(strict_types=1);

use App\Models\Employee;
use App\Models\Office;
use App\Models\ScheduleOverride;
use App\Models\ShiftTemplate;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

it('rejects a second override for the same employee and date', function (): void {
    $emp = Employee::factory()->create();
    ScheduleOverride::create(['employee_id' => $emp->id, 'date' => '2026-08-22', 'is_rest' => true]);
    expect(fn () => ScheduleOverride::create(['employee_id' => $emp->id, 'date' => '2026-08-22', 'is_rest' => true]))
        ->toThrow(QueryException::class);
});

it('rejects a rest override carrying hours (is_rest XOR hours)', function (): void {
    $emp = Employee::factory()->create();
    expect(fn () => DB::table('schedule_overrides')->insert([
        'id' => Str::uuid7()->toString(), 'employee_id' => $emp->id, 'date' => '2026-08-22',
        'is_rest' => true, 'start_minute' => 480, 'end_minute' => 1080, 'break_minutes' => 60,
        'created_at' => now(), 'updated_at' => now(),
    ]))->toThrow(QueryException::class);
});

it('rejects a working override missing hours (is_rest XOR hours)', function (): void {
    $emp = Employee::factory()->create();
    expect(fn () => DB::table('schedule_overrides')->insert([
        'id' => Str::uuid7()->toString(), 'employee_id' => $emp->id, 'date' => '2026-08-22',
        'is_rest' => false, 'start_minute' => null, 'end_minute' => null, 'break_minutes' => null,
        'created_at' => now(), 'updated_at' => now(),
    ]))->toThrow(QueryException::class);
});

it('accepts a valid cross-midnight override and rejects one beyond start + 1440', function (): void {
    $emp = Employee::factory()->create();
    // 17:00 -> 03:00 == start 1020, end 1620: valid
    ScheduleOverride::create([
        'employee_id' => $emp->id, 'date' => '2026-08-22',
        'is_rest' => false, 'start_minute' => 1020, 'end_minute' => 1620, 'break_minutes' => 60,
    ]);
    expect(ScheduleOverride::count())->toBe(1);

    // end beyond start + 1440 invalid: a shift may span at most 24h. start 1020, end 2461 (> 2460).
    $emp2 = Employee::factory()->create();
    expect(fn () => DB::table('schedule_overrides')->insert([
        'id' => Str::uuid7()->toString(), 'employee_id' => $emp2->id, 'date' => '2026-08-22',
        'is_rest' => false, 'start_minute' => 1020, 'end_minute' => 2461, 'break_minutes' => 0,
        'created_at' => now(), 'updated_at' => now(),
    ]))->toThrow(QueryException::class);
});

it('sets an office default template and nulls it when the template is deleted', function (): void {
    $office = Office::factory()->create();
    $t = ShiftTemplate::create(['office_id' => $office->id, 'name' => 'Default']);
    $office->update(['default_shift_template_id' => $t->id]);
    expect($office->fresh()->default_shift_template_id)->toBe($t->id);
    $t->delete();
    expect($office->fresh()->default_shift_template_id)->toBeNull();
});
