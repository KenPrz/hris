<?php

declare(strict_types=1);

use App\Domain\Pay\DayType;
use App\Models\Holiday;
use App\Models\Office;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Spatie\Activitylog\Models\Activity;

uses(RefreshDatabase::class);

/*
| M4a Task 5: clone-from-previous-year. Copies last year's holidays to the same
| month/day of the target year — never a +365-day shift, which would land on the
| wrong day after a leap year — skipping any target date already occupied, so a
| second identical clone is a no-op rather than a duplicate or an error.
*/

// holidayOffice() / hrAdminOf() are declared once in HolidayReadWriteTest.php and shared
// globally across this directory's tests — Pest loads every Feature test file before
// running any of them, so redeclaring the same top-level function name here would be a
// fatal "cannot redeclare" error.

it('clones a year of holidays to the same month/day of the target year, and logs a summary', function (): void {
    $manila = holidayOffice();
    $hrUser = hrAdminOf($manila);

    $newYear = Holiday::factory()->for($manila, 'office')->create(['date' => '2025-01-01', 'day_type' => DayType::RegularHoliday, 'name' => 'New Year']);
    $independence = Holiday::factory()->for($manila, 'office')->create(['date' => '2025-06-12', 'day_type' => DayType::RegularHoliday, 'name' => 'Independence Day']);
    $christmas = Holiday::factory()->for($manila, 'office')->create(['date' => '2025-12-25', 'day_type' => DayType::RegularHoliday, 'name' => 'Christmas']);

    Sanctum::actingAs($hrUser);

    $response = $this->postJson('/api/v1/office/holidays/clone', [
        'office_id' => $manila->id,
        'from_year' => 2025,
        'to_year' => 2026,
    ])->assertCreated();

    expect($response->json('data.*.date'))->toEqualCanonicalizing(['2026-01-01', '2026-06-12', '2026-12-25'])
        ->and($response->json('data.*.name'))->toEqualCanonicalizing(['New Year', 'Independence Day', 'Christmas']);

    $this->assertDatabaseHas('holidays', ['office_id' => $manila->id, 'date' => '2026-01-01', 'name' => 'New Year']);
    $this->assertDatabaseHas('holidays', ['office_id' => $manila->id, 'date' => '2026-06-12', 'name' => 'Independence Day']);
    $this->assertDatabaseHas('holidays', ['office_id' => $manila->id, 'date' => '2026-12-25', 'name' => 'Christmas']);
    $this->assertDatabaseCount('holidays', 6); // three 2025 sources + three 2026 clones

    $activity = Activity::query()
        ->where('subject_id', $manila->id)
        ->where('description', 'cloned holidays')
        ->first();

    expect($activity)->not->toBeNull()
        ->and($activity->causer_id)->toBe($hrUser->id)
        ->and($activity->subject_type)->toBe(Office::class)
        ->and($activity->properties->get('from_year'))->toBe(2025)
        ->and($activity->properties->get('to_year'))->toBe(2026)
        ->and($activity->properties->get('created'))->toBe(3);
});

it('is re-runnable: cloning the same year again creates zero rows and does not error', function (): void {
    $manila = holidayOffice();
    $hrUser = hrAdminOf($manila);

    Holiday::factory()->for($manila, 'office')->create(['date' => '2025-01-01', 'day_type' => DayType::RegularHoliday, 'name' => 'New Year']);

    Sanctum::actingAs($hrUser);

    $this->postJson('/api/v1/office/holidays/clone', [
        'office_id' => $manila->id,
        'from_year' => 2025,
        'to_year' => 2026,
    ])->assertCreated();

    $this->assertDatabaseCount('holidays', 2);

    $second = $this->postJson('/api/v1/office/holidays/clone', [
        'office_id' => $manila->id,
        'from_year' => 2025,
        'to_year' => 2026,
    ])->assertCreated();

    expect($second->json('data'))->toBe([]);
    $this->assertDatabaseCount('holidays', 2); // unchanged — nothing duplicated
});

it('leaves an already-occupied target date as-is rather than overwriting it', function (): void {
    $manila = holidayOffice();
    $hrUser = hrAdminOf($manila);

    Holiday::factory()->for($manila, 'office')->create(['date' => '2025-08-21', 'day_type' => DayType::RegularHoliday, 'name' => 'Ninoy Aquino Day']);
    $existingTarget = Holiday::factory()->for($manila, 'office')->create(['date' => '2026-08-21', 'day_type' => DayType::SpecialNonWorking, 'name' => 'Already Set']);

    Sanctum::actingAs($hrUser);

    $response = $this->postJson('/api/v1/office/holidays/clone', [
        'office_id' => $manila->id,
        'from_year' => 2025,
        'to_year' => 2026,
    ])->assertCreated();

    expect($response->json('data'))->toBe([]); // the target date was already occupied — skipped

    $this->assertDatabaseHas('holidays', [
        'id' => $existingTarget->id,
        'date' => '2026-08-21',
        'day_type' => 'special_non_working',
        'name' => 'Already Set',
    ]);
    $this->assertDatabaseCount('holidays', 2); // the 2025 source + the untouched 2026 row
});

it('skips a Feb 29 source when the target year is not a leap year, never shifting to Mar 1', function (): void {
    $manila = holidayOffice();
    $hrUser = hrAdminOf($manila);

    // 2024 is a leap year; 2026 is not.
    Holiday::factory()->for($manila, 'office')->create(['date' => '2024-02-29', 'day_type' => DayType::SpecialNonWorking, 'name' => 'Leap Day Observance']);

    Sanctum::actingAs($hrUser);

    $response = $this->postJson('/api/v1/office/holidays/clone', [
        'office_id' => $manila->id,
        'from_year' => 2024,
        'to_year' => 2026,
    ])->assertCreated();

    expect($response->json('data'))->toBe([]);

    // 2026-02-29 isn't a representable Postgres date at all — the only way to prove it
    // wasn't created is that nothing landed in 2026, neither on the missing Feb 29 nor
    // shifted to Mar 1.
    $this->assertDatabaseMissing('holidays', ['office_id' => $manila->id, 'date' => '2026-03-01']);
    $this->assertDatabaseCount('holidays', 1); // only the 2024 source — the clone created nothing
});

it('404s cloning for an out-of-scope office, identically to a fabricated office', function (): void {
    $manila = holidayOffice();
    $cebu = holidayOffice();
    $hrUser = hrAdminOf($manila);

    Holiday::factory()->for($cebu, 'office')->create(['date' => '2025-01-01']);

    Sanctum::actingAs($hrUser);

    $payload = fn (string $officeId): array => [
        'office_id' => $officeId,
        'from_year' => 2025,
        'to_year' => 2026,
    ];

    $outOfScope = $this->postJson('/api/v1/office/holidays/clone', $payload($cebu->id))
        ->assertStatus(404);

    $fabricated = $this->postJson('/api/v1/office/holidays/clone', $payload((string) Str::uuid7()))
        ->assertStatus(404);

    $outOfScope->assertExactJson($fabricated->json());
    $outOfScope->assertJsonPath('error.code', 'not_found');

    $this->assertDatabaseCount('holidays', 1); // untouched
});

it('400s a malformed office_id rather than 500ing on the uuid cast', function (): void {
    $manila = holidayOffice();
    Sanctum::actingAs(hrAdminOf($manila));

    $this->postJson('/api/v1/office/holidays/clone', [
        'office_id' => 'not-a-uuid',
        'from_year' => 2025,
        'to_year' => 2026,
    ])
        ->assertStatus(400)
        ->assertJsonPath('error.code', 'validation_failed');
});

it('copies the same month/day across a leap boundary, never a +365-day shift', function (): void {
    // 2023 → 2024 crosses Feb 29. A naive addDays(365) would land 2023-03-15 on 2024-03-14;
    // the same month/day rule keeps it on 2024-03-15. Only a leap-crossing year pair can tell
    // the two apart, so this is the case that actually pins the "never +365" property.
    $manila = holidayOffice();
    $hrUser = hrAdminOf($manila);
    Holiday::factory()->for($manila, 'office')->create(['date' => '2023-03-15', 'day_type' => DayType::SpecialNonWorking, 'name' => 'Founding Day']);

    Sanctum::actingAs($hrUser);

    $this->postJson('/api/v1/office/holidays/clone', [
        'office_id' => $manila->id, 'from_year' => 2023, 'to_year' => 2024,
    ])->assertCreated()
        ->assertJsonPath('data.0.date', '2024-03-15');   // same month/day, not 03-14
});
