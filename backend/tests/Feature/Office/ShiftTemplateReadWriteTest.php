<?php

declare(strict_types=1);

use App\Models\Office;
use App\Models\ShiftTemplate;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Spatie\Activitylog\Models\Activity;

uses(RefreshDatabase::class);

/*
| M4b Task 5: list + create shift templates. Mirrors M4a's HolidayReadWriteTest harness —
| office-scoped access that 404s uniformly, and the activity log on a uuid subject.
*/

function scheduleOffice(): Office
{
    return Office::factory()->create();
}

function hrAdminOfSchedule(Office ...$offices): User
{
    $user = User::factory()->create();

    foreach ($offices as $office) {
        $user->hrAdminOffices()->attach($office->id);
    }

    return $user;
}

/** @return array<int, array<string, mixed>> */
function sevenWorkingDays(): array
{
    return collect(range(0, 6))->map(fn (int $wd) => $wd < 5
        ? ['weekday' => $wd, 'is_rest' => false, 'start_minute' => 480, 'end_minute' => 1080, 'break_minutes' => 60]
        : ['weekday' => $wd, 'is_rest' => true])->all();
}

/** @return array<int, array<string, mixed>> */
function sevenRestDays(): array
{
    return collect(range(0, 6))->map(fn (int $wd) => ['weekday' => $wd, 'is_rest' => true])->all();
}

// --- Create -------------------------------------------------------------

it('creates a template with seven days for an administered office, and logs it', function (): void {
    $office = scheduleOffice();
    $hr = hrAdminOfSchedule($office);
    Sanctum::actingAs($hr);

    $days = sevenWorkingDays();

    $res = $this->postJson('/api/v1/office/shift-templates', [
        'office_id' => $office->id,
        'name' => 'Office',
        'days' => $days,
    ])->assertCreated();

    expect($res->json('data.days'))->toHaveCount(7)
        ->and($res->json('data.office_id'))->toBe($office->id)
        ->and($res->json('data.name'))->toBe('Office');

    // days ordered by weekday, 0..6
    expect($res->json('data.days.*.weekday'))->toBe([0, 1, 2, 3, 4, 5, 6]);

    $templateId = $res->json('data.id');

    $this->assertDatabaseHas('shift_templates', [
        'id' => $templateId,
        'office_id' => $office->id,
        'name' => 'Office',
    ]);
    $this->assertDatabaseCount('shift_template_days', 7);

    $activity = Activity::query()->where('subject_id', $templateId)->first();

    expect($activity)->not->toBeNull()
        ->and($activity->causer_id)->toBe($hr->id)
        ->and($activity->subject_id)->toBe($templateId)
        ->and($activity->subject_type)->toBe(ShiftTemplate::class);
});

it('rejects days that are not exactly the seven weekdays', function (): void {
    $office = scheduleOffice();
    $hr = hrAdminOfSchedule($office);
    Sanctum::actingAs($hr);

    $this->postJson('/api/v1/office/shift-templates', [
        'office_id' => $office->id,
        'name' => 'X',
        'days' => [['weekday' => 0, 'is_rest' => true]],
    ])->assertStatus(400)->assertJsonPath('error.code', 'validation_failed');
});

it('rejects a rest day carrying hours', function (): void {
    $office = scheduleOffice();
    $hr = hrAdminOfSchedule($office);
    Sanctum::actingAs($hr);

    $days = sevenRestDays();
    $days[0]['start_minute'] = 480;
    $days[0]['end_minute'] = 1080;
    $days[0]['break_minutes'] = 60;

    $this->postJson('/api/v1/office/shift-templates', [
        'office_id' => $office->id,
        'name' => 'X',
        'days' => $days,
    ])->assertStatus(400)->assertJsonPath('error.code', 'validation_failed');
});

it('rejects a working day missing hours', function (): void {
    $office = scheduleOffice();
    $hr = hrAdminOfSchedule($office);
    Sanctum::actingAs($hr);

    $days = sevenWorkingDays();
    unset($days[0]['start_minute'], $days[0]['end_minute'], $days[0]['break_minutes']);

    $this->postJson('/api/v1/office/shift-templates', [
        'office_id' => $office->id,
        'name' => 'X',
        'days' => $days,
    ])->assertStatus(400)->assertJsonPath('error.code', 'validation_failed');
});

it('rejects a working day with invalid minute ranges', function (): void {
    $office = scheduleOffice();
    $hr = hrAdminOfSchedule($office);
    Sanctum::actingAs($hr);

    $days = sevenWorkingDays();
    // end <= start
    $days[0]['start_minute'] = 1080;
    $days[0]['end_minute'] = 480;

    $this->postJson('/api/v1/office/shift-templates', [
        'office_id' => $office->id,
        'name' => 'X',
        'days' => $days,
    ])->assertStatus(400)->assertJsonPath('error.code', 'validation_failed');
});

it('404s creating for an office not administered, identically to a fabricated office', function (): void {
    $mine = scheduleOffice();
    $other = scheduleOffice();
    $hr = hrAdminOfSchedule($mine);
    Sanctum::actingAs($hr);

    $body = fn (string $id) => [
        'office_id' => $id,
        'name' => 'X',
        'days' => sevenRestDays(),
    ];

    $oos = $this->postJson('/api/v1/office/shift-templates', $body($other->id))->assertStatus(404);
    $fake = $this->postJson('/api/v1/office/shift-templates', $body((string) Str::uuid7()))->assertStatus(404);

    $oos->assertExactJson($fake->json());
    $oos->assertJsonPath('error.code', 'not_found');

    $this->assertDatabaseCount('shift_templates', 0);
});

// --- List -----------------------------------------------------------------

it('lists the office\'s templates with days', function (): void {
    $office = scheduleOffice();
    $other = scheduleOffice();
    $hr = hrAdminOfSchedule($office);
    Sanctum::actingAs($hr);

    $mineId = $this->postJson('/api/v1/office/shift-templates', [
        'office_id' => $office->id,
        'name' => 'Mine',
        'days' => sevenWorkingDays(),
    ])->assertCreated()->json('data.id');

    // Belongs to a different office — must not show up.
    $otherTemplate = ShiftTemplate::query()->create(['office_id' => $other->id, 'name' => 'Other']);
    $otherTemplate->days()->create(['weekday' => 0, 'is_rest' => true]);

    $res = $this->getJson("/api/v1/office/shift-templates?office={$office->id}")->assertOk();

    expect($res->json('data.*.id'))->toBe([$mineId])
        ->and($res->json('data.0.days'))->toHaveCount(7);
});

it('404s listing an out-of-scope office, identically to a fabricated office', function (): void {
    $mine = scheduleOffice();
    $other = scheduleOffice();
    $hr = hrAdminOfSchedule($mine);
    Sanctum::actingAs($hr);

    $oos = $this->getJson("/api/v1/office/shift-templates?office={$other->id}")->assertStatus(404);
    $fake = $this->getJson('/api/v1/office/shift-templates?office='.(string) Str::uuid7())->assertStatus(404);

    $oos->assertExactJson($fake->json());
    $oos->assertJsonPath('error.code', 'not_found');
});
