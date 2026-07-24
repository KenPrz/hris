<?php
declare(strict_types=1);

use App\Models\Department;
use App\Models\Employee;
use App\Models\Office;
use App\Models\ScheduleAssignment;
use App\Models\ShiftTemplate;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

function assignmentTemplate(): ShiftTemplate {
    $office = Office::factory()->create();
    return ShiftTemplate::create(['office_id' => $office->id, 'name' => 'T']);
}

it('rejects an assignment targeting neither employee nor department', function (): void {
    $t = assignmentTemplate();
    expect(fn () => DB::table('schedule_assignments')->insert([
        'id' => Str::uuid7()->toString(), 'shift_template_id' => $t->id,
        'employee_id' => null, 'department_id' => null, 'effective_from' => '2026-08-01',
        'created_at' => now(), 'updated_at' => now(),
    ]))->toThrow(QueryException::class);
});

it('rejects an assignment targeting both employee and department', function (): void {
    $t = assignmentTemplate();
    $emp = Employee::factory()->create();
    $dept = Department::factory()->create();
    expect(fn () => DB::table('schedule_assignments')->insert([
        'id' => Str::uuid7()->toString(), 'shift_template_id' => $t->id,
        'employee_id' => $emp->id, 'department_id' => $dept->id, 'effective_from' => '2026-08-01',
        'created_at' => now(), 'updated_at' => now(),
    ]))->toThrow(QueryException::class);
});

it('rejects a second assignment for the same employee on the same effective date', function (): void {
    $t = assignmentTemplate();
    $emp = Employee::factory()->create();
    ScheduleAssignment::create(['shift_template_id' => $t->id, 'employee_id' => $emp->id, 'effective_from' => '2026-08-01']);
    expect(fn () => ScheduleAssignment::create(['shift_template_id' => $t->id, 'employee_id' => $emp->id, 'effective_from' => '2026-08-01']))
        ->toThrow(QueryException::class);
});

it('rejects a second assignment for the same department on the same effective date', function (): void {
    $t = assignmentTemplate();
    $dept = Department::factory()->create();
    ScheduleAssignment::create(['shift_template_id' => $t->id, 'department_id' => $dept->id, 'effective_from' => '2026-08-01']);
    expect(fn () => ScheduleAssignment::create(['shift_template_id' => $t->id, 'department_id' => $dept->id, 'effective_from' => '2026-08-01']))
        ->toThrow(QueryException::class);
});

it('allows two different employees to share an effective date', function (): void {
    $t = assignmentTemplate();
    $a = Employee::factory()->create(); $b = Employee::factory()->create();
    ScheduleAssignment::create(['shift_template_id' => $t->id, 'employee_id' => $a->id, 'effective_from' => '2026-08-01']);
    ScheduleAssignment::create(['shift_template_id' => $t->id, 'employee_id' => $b->id, 'effective_from' => '2026-08-01']);
    expect(ScheduleAssignment::count())->toBe(2);
});
