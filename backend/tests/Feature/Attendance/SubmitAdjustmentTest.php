<?php

declare(strict_types=1);

use App\Models\AttendanceLog;
use App\Models\Employee;
use App\Models\Office;
use App\Models\Request;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

function adjustmentActingEmployee(): Employee
{
    $office = Office::factory()->create(['ip_allowlist' => null]);
    $user = User::factory()->create();
    $employee = Employee::factory()->for($user)->create(['current_office_id' => $office->id]);
    Sanctum::actingAs($user);

    return $employee;
}

it('submits an add adjustment', function (): void {
    $employee = adjustmentActingEmployee();

    $this->postJson('/api/v1/attendance/adjustments', [
        'operation' => 'add',
        'note' => 'Forgot to clock in this morning.',
        'direction' => 'in',
        'punched_at' => '2026-07-20T08:00:00Z',
    ])
        ->assertCreated()
        ->assertJsonPath('data.type', 'attendance_adjustment')
        ->assertJsonPath('data.state', 'pending')
        ->assertJsonPath('data.employee_id', $employee->id)
        ->assertJsonPath('data.detail.operation', 'add')
        ->assertJsonPath('data.detail.direction', 'in')
        ->assertJsonPath('data.has_attachment', false);

    expect(Request::count())->toBe(1);

    $request = Request::query()->first();
    expect($request->employee_id)->toBe($employee->id)
        ->and($request->attendanceAdjustmentDetail->operation->value)->toBe('add')
        ->and($request->attendanceAdjustmentDetail->target_log_id)->toBeNull();
});

it('submits a void adjustment against an existing log', function (): void {
    $employee = adjustmentActingEmployee();
    $log = AttendanceLog::factory()->create(['employee_id' => $employee->id]);

    $this->postJson('/api/v1/attendance/adjustments', [
        'operation' => 'void',
        'note' => 'Duplicate punch, please remove.',
        'target_log_id' => $log->id,
    ])
        ->assertCreated()
        ->assertJsonPath('data.detail.operation', 'void')
        ->assertJsonPath('data.detail.target_log_id', $log->id)
        ->assertJsonPath('data.detail.direction', null);

    $detail = Request::query()->first()->attendanceAdjustmentDetail;
    expect($detail->target_log_id)->toBe($log->id)
        ->and($detail->direction)->toBeNull()
        ->and($detail->punched_at)->toBeNull();
});

it('submits an amend adjustment with target, direction, and punched_at', function (): void {
    $employee = adjustmentActingEmployee();
    $log = AttendanceLog::factory()->create(['employee_id' => $employee->id]);

    $this->postJson('/api/v1/attendance/adjustments', [
        'operation' => 'amend',
        'note' => 'Wrong time recorded.',
        'target_log_id' => $log->id,
        'direction' => 'out',
        'punched_at' => '2026-07-20T18:00:00Z',
    ])
        ->assertCreated()
        ->assertJsonPath('data.detail.operation', 'amend')
        ->assertJsonPath('data.detail.target_log_id', $log->id)
        ->assertJsonPath('data.detail.direction', 'out');

    $detail = Request::query()->first()->attendanceAdjustmentDetail;
    expect($detail->target_log_id)->toBe($log->id)
        ->and($detail->direction->value)->toBe('out')
        ->and($detail->punched_at)->not->toBeNull();
});

it('stores an optional attachment on the request', function (): void {
    Storage::fake('attachments');
    adjustmentActingEmployee();

    // create() with a kilobyte count only fakes the *reported* size/mime (getSize() /
    // getMimeType()) — the temp file it backs is empty on disk. That is enough for the
    // validation-rule tests below (mimes:/max: read the reported values), but Media
    // Library's FileAdder determines the mime type from the real bytes via finfo, so the
    // one test that asserts a real attach needs createWithContent() to back the temp file
    // with actual PDF-shaped content.
    $file = UploadedFile::fake()->createWithContent('proof.pdf', str_repeat('%PDF-1.4'.PHP_EOL, 20));

    $this->postJson('/api/v1/attendance/adjustments', [
        'operation' => 'add',
        'note' => 'Forgot to clock in, proof attached.',
        'direction' => 'in',
        'punched_at' => '2026-07-20T08:00:00Z',
        'attachment' => $file,
    ])
        ->assertCreated()
        ->assertJsonPath('data.has_attachment', true);

    $request = Request::query()->first();
    expect($request->hasMedia('attachment'))->toBeTrue();

    $media = $request->getFirstMedia('attachment');
    expect($media->file_name)->toBe('proof.pdf');

    Storage::disk('attachments')->assertExists($media->getPathRelativeToRoot());
});

it('rejects a submission with no note', function (): void {
    adjustmentActingEmployee();

    $this->postJson('/api/v1/attendance/adjustments', [
        'operation' => 'add',
        'direction' => 'in',
        'punched_at' => '2026-07-20T08:00:00Z',
    ])
        ->assertStatus(400)
        ->assertJsonPath('error.code', 'validation_failed');
});

it('rejects an add with no direction or punched_at', function (): void {
    adjustmentActingEmployee();

    $this->postJson('/api/v1/attendance/adjustments', [
        'operation' => 'add',
        'note' => 'Forgot to clock in.',
    ])
        ->assertStatus(400)
        ->assertJsonPath('error.code', 'validation_failed');
});

it('rejects a void with no target_log_id', function (): void {
    adjustmentActingEmployee();

    $this->postJson('/api/v1/attendance/adjustments', [
        'operation' => 'void',
        'note' => 'Duplicate punch.',
    ])
        ->assertStatus(400)
        ->assertJsonPath('error.code', 'validation_failed');
});

it('rejects a submission from a user with no employee record', function (): void {
    Sanctum::actingAs(User::factory()->create());   // a bare admin account, no employee

    $this->postJson('/api/v1/attendance/adjustments', [
        'operation' => 'add',
        'note' => 'Forgot to clock in.',
        'direction' => 'in',
        'punched_at' => '2026-07-20T08:00:00Z',
    ])
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'not_an_employee');
});

it('rejects an oversized attachment', function (): void {
    Storage::fake('attachments');
    adjustmentActingEmployee();

    $file = UploadedFile::fake()->create('proof.pdf', 20000, 'application/pdf');   // > 10240 KB

    $this->postJson('/api/v1/attendance/adjustments', [
        'operation' => 'add',
        'note' => 'Forgot to clock in.',
        'direction' => 'in',
        'punched_at' => '2026-07-20T08:00:00Z',
        'attachment' => $file,
    ])
        ->assertStatus(400)
        ->assertJsonPath('error.code', 'validation_failed');
});

it('rejects an attachment of the wrong type', function (): void {
    Storage::fake('attachments');
    adjustmentActingEmployee();

    $file = UploadedFile::fake()->create('proof.exe', 100, 'application/octet-stream');

    $this->postJson('/api/v1/attendance/adjustments', [
        'operation' => 'add',
        'note' => 'Forgot to clock in.',
        'direction' => 'in',
        'punched_at' => '2026-07-20T08:00:00Z',
        'attachment' => $file,
    ])
        ->assertStatus(400)
        ->assertJsonPath('error.code', 'validation_failed');
});
