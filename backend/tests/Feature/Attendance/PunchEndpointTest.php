<?php

declare(strict_types=1);

use App\Models\AttendanceLog;
use App\Models\Employee;
use App\Models\Office;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

function actingEmployee(array $officeOverrides = []): User
{
    $office = Office::factory()->create($officeOverrides);
    $user = User::factory()->create();
    Employee::factory()->for($user)->create(['current_office_id' => $office->id]);
    Sanctum::actingAs($user);

    return $user;
}

it('records a self-service clock-in', function (): void {
    actingEmployee(['ip_allowlist' => null]);

    $this->postJson('/api/v1/attendance/punch', ['direction' => 'in'], ['Idempotency-Key' => 'k1'])
        ->assertCreated()
        ->assertJsonPath('data.direction', 'in')
        ->assertJsonPath('data.source', 'web')
        ->assertJsonPath('data.verification', 'verified');

    expect(AttendanceLog::count())->toBe(1);
});

it('is idempotent under a retried key', function (): void {
    actingEmployee(['ip_allowlist' => null]);
    $headers = ['Idempotency-Key' => 'retry-key'];

    $this->postJson('/api/v1/attendance/punch', ['direction' => 'in'], $headers)->assertCreated();
    $this->postJson('/api/v1/attendance/punch', ['direction' => 'in'], $headers)->assertCreated();

    // The retry replayed the stored response; only one row exists.
    expect(AttendanceLog::count())->toBe(1);
});

it('requires an idempotency key', function (): void {
    actingEmployee(['ip_allowlist' => null]);

    // Decide the contract: a punch without a key is refused. (If the team prefers to allow
    // keyless punches, change this assertion — but the spec makes the key required.)
    $this->postJson('/api/v1/attendance/punch', ['direction' => 'in'])
        ->assertStatus(400)
        ->assertJsonPath('error.code', 'validation_failed');
});

it('flags an off-allowlist punch but still stores it', function (): void {
    actingEmployee(['ip_allowlist' => ['203.0.113.0/24']]);

    // The test client's IP is 127.0.0.1, outside the /24.
    $this->postJson('/api/v1/attendance/punch', ['direction' => 'in'], ['Idempotency-Key' => 'k2'])
        ->assertCreated()
        ->assertJsonPath('data.verification', 'flagged')
        ->assertJsonPath('data.flag_reason', 'ip_not_allowlisted');
});

it('rejects a punch from a user with no employee record', function (): void {
    Sanctum::actingAs(User::factory()->create());   // a bare admin account, no employee

    $this->postJson('/api/v1/attendance/punch', ['direction' => 'in'], ['Idempotency-Key' => 'k3'])
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'not_an_employee');
});
