<?php

declare(strict_types=1);

use App\Models\Employee;
use App\Models\OvertimeDetail;
use App\Models\Request;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

/*
| M6c Task 3: an employee filing their own overtime pre-authorization. Single-hop and
| JSON-only (no attachment) — mirrors SubmitLeaveRequestTest's shape, minus the two-hop
| state branch and the schedule-derived amount: the client sends hours, converted to
| integer minutes in the controller.
*/

function overtimeActingEmployee(): Employee
{
    $user = User::factory()->create();
    $employee = Employee::factory()->for($user)->create();
    Sanctum::actingAs($user);

    return $employee;
}

it('files an overtime request as pending with its detail', function (): void {
    $employee = overtimeActingEmployee();

    $response = $this->postJson('/api/v1/overtime/requests', [
        'date' => '2026-07-15',
        'hours' => 2,
        'note' => 'Month-end close',
    ]);

    $response->assertCreated()
        ->assertJsonPath('data.type', 'overtime')
        ->assertJsonPath('data.state', 'pending')
        ->assertJsonPath('data.employee_id', $employee->id)
        ->assertJsonPath('data.detail.date', '2026-07-15')
        ->assertJsonPath('data.detail.minutes', 120);

    $request = Request::query()->where('type', 'overtime')->sole();
    expect(OvertimeDetail::query()->find($request->id)->minutes)->toBe(120);
});

it('rejects zero or negative hours', function (): void {
    overtimeActingEmployee();

    $this->postJson('/api/v1/overtime/requests', [
        'date' => '2026-07-15',
        'hours' => 0,
        'note' => 'x',
    ])->assertStatus(400)->assertJsonPath('error.code', 'validation_failed');

    expect(Request::count())->toBe(0);
});

it('rejects hours that do not land on a whole minute', function (): void {
    overtimeActingEmployee();

    // 1.001h = 60.06 min — not a whole minute.
    $this->postJson('/api/v1/overtime/requests', [
        'date' => '2026-07-15',
        'hours' => 1.001,
        'note' => 'x',
    ])->assertStatus(400)->assertJsonPath('error.code', 'validation_failed');

    expect(Request::count())->toBe(0);
});
