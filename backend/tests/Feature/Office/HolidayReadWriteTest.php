<?php

declare(strict_types=1);

use App\Domain\Pay\DayType;
use App\Models\Holiday;
use App\Models\Office;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Spatie\Activitylog\Models\Activity;

uses(RefreshDatabase::class);

/*
| M4a Task 3: list + create holidays. Establishes two patterns the rest of M4a
| reuses — office-scoped access that 404s uniformly (never a 403 that would
| confirm an office exists), and the activity log's first real use (causer +
| uuid subject morph).
*/

function holidayOffice(): Office
{
    return Office::factory()->create();
}

function hrAdminOf(Office ...$offices): User
{
    $user = User::factory()->create();

    foreach ($offices as $office) {
        $user->hrAdminOffices()->attach($office->id);
    }

    return $user;
}

// --- Create -------------------------------------------------------------

it('lets an HR admin create a holiday for an office they administer, and logs it', function (): void {
    $manila = holidayOffice();
    $hrUser = hrAdminOf($manila);

    Sanctum::actingAs($hrUser);

    $response = $this->postJson('/api/v1/office/holidays', [
        'office_id' => $manila->id,
        'date' => '2026-08-21',
        'day_type' => 'regular_holiday',
        'name' => 'Ninoy Aquino Day',
    ])->assertCreated();

    $holidayId = $response->json('data.id');

    expect($response->json('data'))->toBe([
        'id' => $holidayId,
        'office_id' => $manila->id,
        'date' => '2026-08-21',
        'day_type' => 'regular_holiday',
        'name' => 'Ninoy Aquino Day',
    ]);

    $this->assertDatabaseHas('holidays', [
        'id' => $holidayId,
        'office_id' => $manila->id,
        'date' => '2026-08-21',
        'day_type' => 'regular_holiday',
        'name' => 'Ninoy Aquino Day',
    ]);

    // The activity-log row proves the uuid morph end to end: causer is the acting
    // HR user, subject is the holiday just created — both by id, not by count.
    $activity = Activity::query()->where('subject_id', $holidayId)->first();

    expect($activity)->not->toBeNull()
        ->and($activity->causer_id)->toBe($hrUser->id)
        ->and($activity->subject_id)->toBe($holidayId)
        ->and($activity->subject_type)->toBe(Holiday::class);
});

it('404s creating a holiday for an office the admin does not administer, identically to a fabricated office', function (): void {
    $manila = holidayOffice();
    $cebu = holidayOffice();
    $hrUser = hrAdminOf($manila);

    Sanctum::actingAs($hrUser);

    $payload = fn (string $officeId): array => [
        'office_id' => $officeId,
        'date' => '2026-08-21',
        'day_type' => 'regular_holiday',
        'name' => 'Ninoy Aquino Day',
    ];

    $outOfScope = $this->postJson('/api/v1/office/holidays', $payload($cebu->id))
        ->assertStatus(404);

    $fabricated = $this->postJson('/api/v1/office/holidays', $payload((string) Str::uuid7()))
        ->assertStatus(404);

    $outOfScope->assertExactJson($fabricated->json());
    $outOfScope->assertJsonPath('error.code', 'not_found');

    $this->assertDatabaseCount('holidays', 0);
});

it('400s an invalid day_type', function (): void {
    $manila = holidayOffice();
    $hrUser = hrAdminOf($manila);

    Sanctum::actingAs($hrUser);

    $this->postJson('/api/v1/office/holidays', [
        'office_id' => $manila->id,
        'date' => '2026-08-21',
        'day_type' => 'ordinary',
        'name' => 'Ninoy Aquino Day',
    ])
        ->assertStatus(400)
        ->assertJsonPath('error.code', 'validation_failed');
});

it('400s a missing name', function (): void {
    $manila = holidayOffice();
    $hrUser = hrAdminOf($manila);

    Sanctum::actingAs($hrUser);

    $this->postJson('/api/v1/office/holidays', [
        'office_id' => $manila->id,
        'date' => '2026-08-21',
        'day_type' => 'regular_holiday',
    ])
        ->assertStatus(400)
        ->assertJsonPath('error.code', 'validation_failed');
});

// --- List -----------------------------------------------------------------

it('lists only the given office and year of holidays, date-ordered', function (): void {
    $manila = holidayOffice();
    $cebu = holidayOffice();
    $hrUser = hrAdminOf($manila);

    $late = Holiday::factory()->for($manila, 'office')->create(['date' => '2026-12-25', 'day_type' => DayType::RegularHoliday, 'name' => 'Christmas']);
    $early = Holiday::factory()->for($manila, 'office')->create(['date' => '2026-01-01', 'day_type' => DayType::RegularHoliday, 'name' => 'New Year']);
    // Wrong year — excluded.
    Holiday::factory()->for($manila, 'office')->create(['date' => '2025-12-25', 'day_type' => DayType::RegularHoliday, 'name' => 'Christmas 2025']);
    // Wrong office — excluded.
    Holiday::factory()->for($cebu, 'office')->create(['date' => '2026-06-12', 'day_type' => DayType::RegularHoliday, 'name' => 'Independence Day']);

    Sanctum::actingAs($hrUser);

    $response = $this->getJson("/api/v1/office/holidays?office={$manila->id}&year=2026")
        ->assertOk();

    expect($response->json('data.*.id'))->toBe([$early->id, $late->id])
        ->and($response->json('data.*.date'))->toBe(['2026-01-01', '2026-12-25']);
});

it('404s listing an out-of-scope office, identically to a fabricated office', function (): void {
    $manila = holidayOffice();
    $cebu = holidayOffice();
    $hrUser = hrAdminOf($manila);

    Holiday::factory()->for($cebu, 'office')->create(['date' => '2026-06-12']);

    Sanctum::actingAs($hrUser);

    $outOfScope = $this->getJson("/api/v1/office/holidays?office={$cebu->id}&year=2026")
        ->assertStatus(404);

    $fabricated = $this->getJson('/api/v1/office/holidays?office='.(string) Str::uuid7().'&year=2026')
        ->assertStatus(404);

    $outOfScope->assertExactJson($fabricated->json());
    $outOfScope->assertJsonPath('error.code', 'not_found');
});

it('400s a malformed (non-uuid) office rather than 500ing on the uuid cast', function (): void {
    $manila = holidayOffice();
    Sanctum::actingAs(hrAdminOf($manila));

    // A typo'd URL shouldn't reach Postgres and blow up; ListHolidaysRequest catches the
    // shape. This is not an oracle — it's about format, not existence.
    $this->getJson('/api/v1/office/holidays?office=not-a-uuid&year=2026')
        ->assertStatus(400)
        ->assertJsonPath('error.code', 'validation_failed');

    $this->getJson("/api/v1/office/holidays?office={$manila->id}&year=nope")
        ->assertStatus(400)
        ->assertJsonPath('error.code', 'validation_failed');
});

// --- Update -----------------------------------------------------------------

it('lets an HR admin update a holiday for an office they administer, and logs it', function (): void {
    $manila = holidayOffice();
    $hrUser = hrAdminOf($manila);
    $holiday = Holiday::factory()->for($manila, 'office')->create([
        'date' => '2026-08-21',
        'day_type' => DayType::RegularHoliday,
        'name' => 'Ninoy Aquino Day',
    ]);

    Sanctum::actingAs($hrUser);

    $response = $this->patchJson("/api/v1/office/holidays/{$holiday->id}", [
        'day_type' => 'special_non_working',
        'name' => 'Ninoy Aquino Day (Observed)',
    ])->assertOk();

    expect($response->json('data'))->toBe([
        'id' => $holiday->id,
        'office_id' => $manila->id,
        'date' => '2026-08-21',
        'day_type' => 'special_non_working',
        'name' => 'Ninoy Aquino Day (Observed)',
    ]);

    $this->assertDatabaseHas('holidays', [
        'id' => $holiday->id,
        'day_type' => 'special_non_working',
        'name' => 'Ninoy Aquino Day (Observed)',
    ]);

    $activity = Activity::query()
        ->where('subject_id', $holiday->id)
        ->where('event', 'updated')
        ->first();

    expect($activity)->not->toBeNull()
        ->and($activity->causer_id)->toBe($hrUser->id)
        ->and($activity->subject_type)->toBe(Holiday::class);
});

it('404s updating a holiday belonging to an office the admin does not administer, identically to a fabricated holiday', function (): void {
    $manila = holidayOffice();
    $cebu = holidayOffice();
    $hrUser = hrAdminOf($manila);
    $outOfScopeHoliday = Holiday::factory()->for($cebu, 'office')->create();

    Sanctum::actingAs($hrUser);

    $payload = [
        'day_type' => 'special_non_working',
        'name' => 'Ninoy Aquino Day (Observed)',
    ];

    $outOfScope = $this->patchJson("/api/v1/office/holidays/{$outOfScopeHoliday->id}", $payload)
        ->assertStatus(404);

    $fabricated = $this->patchJson('/api/v1/office/holidays/'.(string) Str::uuid7(), $payload)
        ->assertStatus(404);

    $outOfScope->assertExactJson($fabricated->json());
    $outOfScope->assertJsonPath('error.code', 'not_found');

    $this->assertDatabaseHas('holidays', [
        'id' => $outOfScopeHoliday->id,
        'day_type' => $outOfScopeHoliday->day_type->value,
        'name' => $outOfScopeHoliday->name,
    ]);
});

it('400s updating a holiday with an invalid day_type', function (): void {
    $manila = holidayOffice();
    $hrUser = hrAdminOf($manila);
    $holiday = Holiday::factory()->for($manila, 'office')->create();

    Sanctum::actingAs($hrUser);

    $this->patchJson("/api/v1/office/holidays/{$holiday->id}", [
        'day_type' => 'ordinary',
        'name' => 'Ninoy Aquino Day',
    ])
        ->assertStatus(400)
        ->assertJsonPath('error.code', 'validation_failed');
});

// --- Delete -----------------------------------------------------------------

it('lets an HR admin delete a holiday for an office they administer, and logs it', function (): void {
    $manila = holidayOffice();
    $hrUser = hrAdminOf($manila);
    $holiday = Holiday::factory()->for($manila, 'office')->create();

    Sanctum::actingAs($hrUser);

    $this->deleteJson("/api/v1/office/holidays/{$holiday->id}")
        ->assertStatus(204)
        ->assertNoContent();

    $this->assertDatabaseMissing('holidays', ['id' => $holiday->id]);

    $activity = Activity::query()
        ->where('subject_id', $holiday->id)
        ->where('event', 'deleted')
        ->first();

    expect($activity)->not->toBeNull()
        ->and($activity->causer_id)->toBe($hrUser->id)
        ->and($activity->subject_type)->toBe(Holiday::class);
});

it('404s deleting a holiday belonging to an office the admin does not administer, identically to a fabricated holiday', function (): void {
    $manila = holidayOffice();
    $cebu = holidayOffice();
    $hrUser = hrAdminOf($manila);
    $outOfScopeHoliday = Holiday::factory()->for($cebu, 'office')->create();

    Sanctum::actingAs($hrUser);

    $outOfScope = $this->deleteJson("/api/v1/office/holidays/{$outOfScopeHoliday->id}")
        ->assertStatus(404);

    $fabricated = $this->deleteJson('/api/v1/office/holidays/'.(string) Str::uuid7())
        ->assertStatus(404);

    $outOfScope->assertExactJson($fabricated->json());
    $outOfScope->assertJsonPath('error.code', 'not_found');

    $this->assertDatabaseHas('holidays', ['id' => $outOfScopeHoliday->id]);
});
