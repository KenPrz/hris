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
| M4b Task 9: PATCH /office/default-template. Sets offices.default_shift_template_id —
| the last piece ScheduleResolver needs to fall through to when neither an employee nor a
| department assignment covers a date. The template must belong to the same office as the
| target office; resolving it scoped to that office collapses "template from another
| office" and "fabricated template id" into one identical 404 alongside "office not
| administered" — mirrors CreateAssignmentController's template-must-belong-to-office 404.
*/

function defaultTemplateOffice(): Office
{
    return Office::factory()->create();
}

function hrAdminOfDefaultTemplate(Office ...$offices): User
{
    $user = User::factory()->create();

    foreach ($offices as $office) {
        $user->hrAdminOffices()->attach($office->id);
    }

    return $user;
}

function defaultTemplateFor(Office $office): ShiftTemplate
{
    return ShiftTemplate::query()->create(['office_id' => $office->id, 'name' => 'Template']);
}

it('sets the default template for an administered office, and logs it', function (): void {
    $office = defaultTemplateOffice();
    $hr = hrAdminOfDefaultTemplate($office);
    Sanctum::actingAs($hr);

    $template = defaultTemplateFor($office);

    $res = $this->patchJson('/api/v1/office/default-template', [
        'office_id' => $office->id,
        'template_id' => $template->id,
    ])->assertOk();

    expect($res->json('data.id'))->toBe($office->id)
        ->and($res->json('data.default_shift_template_id'))->toBe($template->id);

    $this->assertDatabaseHas('offices', [
        'id' => $office->id,
        'default_shift_template_id' => $template->id,
    ]);

    // Office self-logs (M8a's LogsActivity), including its own factory-created `created`
    // event above — which predates Sanctum::actingAs() and so has no causer. The `update`
    // this endpoint performs is the entry under test, so it's the one to fetch.
    $activity = Activity::query()->where('subject_id', $office->id)->where('event', 'updated')->first();

    expect($activity)->not->toBeNull()
        ->and($activity->causer_id)->toBe($hr->id)
        ->and($activity->subject_type)->toBe(Office::class);
});

it('404s setting a template from another office, identically to a fabricated template', function (): void {
    $mine = defaultTemplateOffice();
    $other = defaultTemplateOffice();
    $hr = hrAdminOfDefaultTemplate($mine);
    Sanctum::actingAs($hr);

    $theirTemplate = defaultTemplateFor($other);

    $body = fn (string $templateId) => [
        'office_id' => $mine->id,
        'template_id' => $templateId,
    ];

    $foreign = $this->patchJson('/api/v1/office/default-template', $body($theirTemplate->id))->assertStatus(404);
    $fake = $this->patchJson('/api/v1/office/default-template', $body((string) Str::uuid7()))->assertStatus(404);

    $foreign->assertExactJson($fake->json());
    $foreign->assertJsonPath('error.code', 'not_found');

    $this->assertDatabaseHas('offices', ['id' => $mine->id, 'default_shift_template_id' => null]);
});

it('404s setting for an office not administered', function (): void {
    $mine = defaultTemplateOffice();
    $other = defaultTemplateOffice();
    $hr = hrAdminOfDefaultTemplate($mine);
    Sanctum::actingAs($hr);

    $theirTemplate = defaultTemplateFor($other);

    $oos = $this->patchJson('/api/v1/office/default-template', [
        'office_id' => $other->id,
        'template_id' => $theirTemplate->id,
    ])->assertStatus(404);

    $fake = $this->patchJson('/api/v1/office/default-template', [
        'office_id' => (string) Str::uuid7(),
        'template_id' => $theirTemplate->id,
    ])->assertStatus(404);

    $oos->assertExactJson($fake->json());
    $oos->assertJsonPath('error.code', 'not_found');
});

it('rejects a malformed body', function (): void {
    $office = defaultTemplateOffice();
    $hr = hrAdminOfDefaultTemplate($office);
    Sanctum::actingAs($hr);

    $this->patchJson('/api/v1/office/default-template', [
        'office_id' => 'not-a-uuid',
        'template_id' => (string) Str::uuid7(),
    ])->assertStatus(400)->assertJsonPath('error.code', 'validation_failed');
});
