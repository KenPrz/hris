<?php

declare(strict_types=1);

use App\Domain\Attendance\AdjustmentOperation;
use App\Domain\Attendance\PunchDirection;
use App\Domain\Requests\RequestState;
use App\Models\AttendanceAdjustmentDetail;
use App\Models\Employee;
use App\Models\Office;
use App\Models\Request;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

/*
| The read side of the requests spine: my-requests, the approval queue
| (in-scope-minus-self, pending only), a scoped show, and the private
| attachment stream. Visibility is identical for show and download: the
| requester, or an approver for whom RequestAuthority::canDecide() is true;
| anyone else gets 404, never a 403 that would confirm the request exists.
|
| Helper names are prefixed `readAdjustments*` to avoid colliding with the
| same-shaped, differently-named helpers other Attendance test files declare
| as globals in the same process (officeForAdjustments(), employeeWithUser(),
| pendingAddRequest() in AdjustmentTransitionsTest.php).
*/

function readAdjustmentsOffice(): Office
{
    return Office::factory()->create(['ip_allowlist' => null]);
}

/** @return array{0: User, 1: Employee} */
function readAdjustmentsEmployee(Office $office, array $overrides = []): array
{
    $user = User::factory()->create();
    $employee = Employee::factory()->for($user)->create(array_merge(['current_office_id' => $office->id], $overrides));

    return [$user, $employee];
}

function readAdjustmentsPendingRequest(Employee $employee): Request
{
    $request = Request::factory()->for($employee)->create();
    AttendanceAdjustmentDetail::factory()->for($request)->create([
        'operation' => AdjustmentOperation::Add,
        'target_log_id' => null,
        'direction' => PunchDirection::In,
        'punched_at' => '2026-07-20T08:00:00Z',
    ]);

    return $request->fresh();
}

// --- ListMine ---------------------------------------------------------

it('lists only the caller\'s own requests, in any state', function (): void {
    $office = readAdjustmentsOffice();
    [$meUser, $me] = readAdjustmentsEmployee($office);
    [, $other] = readAdjustmentsEmployee($office);

    $mine = readAdjustmentsPendingRequest($me);
    $mineDecided = Request::factory()->for($me)->create(['state' => RequestState::Approved]);
    $notMine = readAdjustmentsPendingRequest($other);

    Sanctum::actingAs($meUser);

    $response = $this->getJson('/api/v1/attendance/adjustments')->assertOk();

    $ids = array_column($response->json('data'), 'id');

    expect($ids)->toContain($mine->id)
        ->toContain($mineDecided->id)
        ->not->toContain($notMine->id)
        ->toHaveCount(2);
});

it('422s the my-requests read for a caller with no employee record', function (): void {
    Sanctum::actingAs(User::factory()->create());

    $this->getJson('/api/v1/attendance/adjustments')
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'not_an_employee');
});

// --- ListPending --------------------------------------------------------

it('lists pending requests the caller may decide, excluding own and out-of-scope', function (): void {
    $office = readAdjustmentsOffice();
    [$managerUser, $manager] = readAdjustmentsEmployee($office);
    [, $report] = readAdjustmentsEmployee($office, ['current_reports_to_id' => $manager->id]);

    $visible = readAdjustmentsPendingRequest($report);
    $ownRequest = readAdjustmentsPendingRequest($manager);   // must be excluded: own

    $otherOffice = readAdjustmentsOffice();
    [, $unrelated] = readAdjustmentsEmployee($otherOffice);
    $outOfScope = readAdjustmentsPendingRequest($unrelated); // must be excluded: out of scope

    $decided = readAdjustmentsPendingRequest($report);
    $decided->update(['state' => RequestState::Approved]);   // must be excluded: not pending

    Sanctum::actingAs($managerUser);

    $response = $this->getJson('/api/v1/attendance/adjustments/pending')->assertOk();

    $ids = array_column($response->json('data'), 'id');

    expect($ids)->toBe([$visible->id])
        ->not->toContain($ownRequest->id)
        ->not->toContain($outOfScope->id)
        ->not->toContain($decided->id);
});

// --- Show -----------------------------------------------------------------

it('shows a request to the requester', function (): void {
    $office = readAdjustmentsOffice();
    [$requesterUser, $requester] = readAdjustmentsEmployee($office);
    $request = readAdjustmentsPendingRequest($requester);

    Sanctum::actingAs($requesterUser);

    $this->getJson("/api/v1/attendance/adjustments/{$request->id}")
        ->assertOk()
        ->assertJsonPath('data.id', $request->id)
        ->assertJsonPath('data.employee_id', $requester->id);
});

it('shows a request to an authorized approver', function (): void {
    $office = readAdjustmentsOffice();
    [$managerUser, $manager] = readAdjustmentsEmployee($office);
    [, $report] = readAdjustmentsEmployee($office, ['current_reports_to_id' => $manager->id]);
    $request = readAdjustmentsPendingRequest($report);

    Sanctum::actingAs($managerUser);

    $this->getJson("/api/v1/attendance/adjustments/{$request->id}")
        ->assertOk()
        ->assertJsonPath('data.id', $request->id);
});

it('404s a request show for an unrelated employee — existence must not leak', function (): void {
    $office = readAdjustmentsOffice();
    [, $requester] = readAdjustmentsEmployee($office);
    $request = readAdjustmentsPendingRequest($requester);

    $otherOffice = readAdjustmentsOffice();
    [$unrelatedUser] = readAdjustmentsEmployee($otherOffice);

    Sanctum::actingAs($unrelatedUser);

    $this->getJson("/api/v1/attendance/adjustments/{$request->id}")
        ->assertStatus(404)
        ->assertJsonPath('error.code', 'not_found');
});

// --- DownloadAttachment -----------------------------------------------------

it('downloads the attachment for the requester', function (): void {
    Storage::fake('attachments');

    $office = readAdjustmentsOffice();
    [$requesterUser, $requester] = readAdjustmentsEmployee($office);
    $request = readAdjustmentsPendingRequest($requester);

    $content = str_repeat('%PDF-1.4'.PHP_EOL, 20);
    $file = UploadedFile::fake()->createWithContent('proof.pdf', $content);
    $request->addMedia($file)->toMediaCollection('attachment');

    Sanctum::actingAs($requesterUser);

    $response = $this->get("/api/v1/attendance/adjustments/{$request->id}/attachment")
        ->assertOk();

    expect($response->headers->get('Content-Type'))->toContain('pdf')
        ->and($response->streamedContent())->toBe($content);
});

it('downloads the attachment for an authorized approver', function (): void {
    Storage::fake('attachments');

    $office = readAdjustmentsOffice();
    [$managerUser, $manager] = readAdjustmentsEmployee($office);
    [, $report] = readAdjustmentsEmployee($office, ['current_reports_to_id' => $manager->id]);
    $request = readAdjustmentsPendingRequest($report);

    $content = str_repeat('%PDF-1.4'.PHP_EOL, 20);
    $file = UploadedFile::fake()->createWithContent('proof.pdf', $content);
    $request->addMedia($file)->toMediaCollection('attachment');

    Sanctum::actingAs($managerUser);

    $response = $this->get("/api/v1/attendance/adjustments/{$request->id}/attachment")
        ->assertOk();

    expect($response->streamedContent())->toBe($content);
});

it('404s the attachment download for an unrelated employee — never leaks the file', function (): void {
    Storage::fake('attachments');

    $office = readAdjustmentsOffice();
    [, $requester] = readAdjustmentsEmployee($office);
    $request = readAdjustmentsPendingRequest($requester);

    $file = UploadedFile::fake()->createWithContent('proof.pdf', str_repeat('%PDF-1.4'.PHP_EOL, 20));
    $request->addMedia($file)->toMediaCollection('attachment');

    $otherOffice = readAdjustmentsOffice();
    [$unrelatedUser] = readAdjustmentsEmployee($otherOffice);

    Sanctum::actingAs($unrelatedUser);

    $this->get("/api/v1/attendance/adjustments/{$request->id}/attachment")
        ->assertStatus(404)
        ->assertJsonPath('error.code', 'not_found');
});

it('404s the attachment download when the request has no attachment', function (): void {
    Storage::fake('attachments');

    $office = readAdjustmentsOffice();
    [$requesterUser, $requester] = readAdjustmentsEmployee($office);
    $request = readAdjustmentsPendingRequest($requester);

    Sanctum::actingAs($requesterUser);

    $this->get("/api/v1/attendance/adjustments/{$request->id}/attachment")
        ->assertStatus(404)
        ->assertJsonPath('error.code', 'not_found');
});
