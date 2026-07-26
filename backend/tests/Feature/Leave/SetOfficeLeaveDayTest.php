<?php

declare(strict_types=1);

use App\Models\Office;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

/*
| M6b-a Task 1: PATCH /office/leave-day. Sets offices.minutes_per_leave_day — the nominal
| length of a leave day (default 480, i.e. 8h), used later to convert readable leave units
| (days/hours) to stored minutes. Mirrors the M4 per-office config idiom: 404-not-403 for
| an office the caller doesn't administer, shape-only validation on office_id.
*/

function leaveDayOffice(): Office
{
    return Office::factory()->create();
}

function hrAdminOfLeaveDay(Office ...$offices): User
{
    $user = User::factory()->create();

    foreach ($offices as $office) {
        $user->hrAdminOffices()->attach($office->id);
    }

    return $user;
}

it('sets minutes_per_leave_day for an administered office', function (): void {
    $office = leaveDayOffice();
    $hr = hrAdminOfLeaveDay($office);
    Sanctum::actingAs($hr);

    $res = $this->patchJson('/api/v1/office/leave-day', [
        'office_id' => $office->id,
        'minutes_per_leave_day' => 450,
    ])->assertOk();

    expect($res->json('data.id'))->toBe($office->id)
        ->and($res->json('data.minutes_per_leave_day'))->toBe(450);

    $this->assertDatabaseHas('offices', [
        'id' => $office->id,
        'minutes_per_leave_day' => 450,
    ]);
});

it('404s setting leave day for an office not administered, identically to a fabricated one', function (): void {
    $mine = leaveDayOffice();
    $other = leaveDayOffice();
    $hr = hrAdminOfLeaveDay($mine);
    Sanctum::actingAs($hr);

    $oos = $this->patchJson('/api/v1/office/leave-day', [
        'office_id' => $other->id,
        'minutes_per_leave_day' => 450,
    ])->assertStatus(404);

    $fake = $this->patchJson('/api/v1/office/leave-day', [
        'office_id' => (string) Str::uuid7(),
        'minutes_per_leave_day' => 450,
    ])->assertStatus(404);

    $oos->assertExactJson($fake->json());
    $oos->assertJsonPath('error.code', 'not_found');

    $this->assertDatabaseHas('offices', ['id' => $mine->id, 'minutes_per_leave_day' => 480]);
});

it('rejects a minutes_per_leave_day below 1', function (): void {
    $office = leaveDayOffice();
    $hr = hrAdminOfLeaveDay($office);
    Sanctum::actingAs($hr);

    $this->patchJson('/api/v1/office/leave-day', [
        'office_id' => $office->id,
        'minutes_per_leave_day' => 0,
    ])->assertStatus(400)->assertJsonPath('error.code', 'validation_failed');

    $this->assertDatabaseHas('offices', ['id' => $office->id, 'minutes_per_leave_day' => 480]);
});
