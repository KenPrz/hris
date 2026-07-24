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
