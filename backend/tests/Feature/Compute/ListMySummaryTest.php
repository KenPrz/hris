<?php

declare(strict_types=1);

use App\Domain\Pay\DayType;
use App\Domain\Pay\SummaryLineKind;
use App\Models\DailyAttendanceSummary;
use App\Models\Employee;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

/*
| Task 7: GET /me/attendance/summary — the computed month, self-scoped. Summaries are
| seeded directly (DailyAttendanceSummary::create) since only the wire shape and scoping
| are under test here, not computation itself (that's ComputeDailySummaryTest's job).
*/

function seedSummary(Employee $employee, string $date, array $overrides = []): DailyAttendanceSummary
{
    return DailyAttendanceSummary::create(array_merge([
        'employee_id' => $employee->id,
        'date' => $date,
        'day_type' => DayType::Ordinary,
        'is_rest_day' => false,
        'scheduled_minutes' => 540,
        'is_art82_exempt' => false,
        'rule_version_id' => null,
        'worked_minutes' => 480,
        'late_minutes' => 0,
        'undertime_minutes' => 0,
        'status' => 'computed',
        'is_incomplete' => false,
        'computed_at' => now(),
    ], $overrides));
}

it('returns my computed month, with lines, ordered by date', function (): void {
    $user = User::factory()->create();
    $me = Employee::factory()->for($user)->create();

    $first = seedSummary($me, '2026-08-03');
    $first->lines()->create(['kind' => SummaryLineKind::RegularDay, 'minutes' => 480, 'applied_bp' => 10000]);

    $second = seedSummary($me, '2026-08-01');
    $second->lines()->create(['kind' => SummaryLineKind::OvertimeDay, 'minutes' => 60, 'applied_bp' => 12500]);
    $second->lines()->create(['kind' => SummaryLineKind::RegularDay, 'minutes' => 480, 'applied_bp' => 10000]);

    // Outside the requested month — must not appear.
    seedSummary($me, '2026-07-31');
    seedSummary($me, '2026-09-01');

    Sanctum::actingAs($user);

    $data = $this->getJson('/api/v1/me/attendance/summary?month=2026-08')->assertOk()->json('data');

    expect($data)->toHaveCount(2)
        ->and($data[0]['date'])->toBe('2026-08-01')
        ->and($data[1]['date'])->toBe('2026-08-03');

    expect($data[0]['lines'])->toHaveCount(2)
        // sorted deterministically by kind: overtime_day < regular_day
        ->and($data[0]['lines'][0]['kind'])->toBe('overtime_day')
        ->and($data[0]['lines'][1]['kind'])->toBe('regular_day');

    expect($data[1])->toMatchArray([
        'date' => '2026-08-03',
        'day_type' => 'ordinary',
        'is_rest_day' => false,
        'scheduled_minutes' => 540,
        'is_art82_exempt' => false,
        'worked_minutes' => 480,
        'late_minutes' => 0,
        'undertime_minutes' => 0,
        'status' => 'computed',
        'is_incomplete' => false,
    ]);
});

it('never returns another employee\'s summaries for the same month', function (): void {
    $user = User::factory()->create();
    $me = Employee::factory()->for($user)->create();
    $someoneElse = Employee::factory()->create();

    seedSummary($me, '2026-08-03');
    seedSummary($someoneElse, '2026-08-03');

    Sanctum::actingAs($user);

    $data = $this->getJson('/api/v1/me/attendance/summary?month=2026-08')->assertOk()->json('data');

    expect($data)->toHaveCount(1)
        ->and($data[0]['date'])->toBe('2026-08-03');
});

it('400s validation_failed on a malformed month', function (): void {
    $user = User::factory()->create();
    Employee::factory()->for($user)->create();
    Sanctum::actingAs($user);

    $this->getJson('/api/v1/me/attendance/summary?month=2026-13')
        ->assertStatus(400)
        ->assertJsonPath('error.code', 'validation_failed');
});

it('422s not_an_employee for a caller with no employee record, same as /me/attendance', function (): void {
    $user = User::factory()->create();
    Sanctum::actingAs($user);

    $this->getJson('/api/v1/me/attendance/summary?month=2026-08')
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'not_an_employee');

    $this->getJson('/api/v1/me/attendance?month=2026-08')
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'not_an_employee');
});
