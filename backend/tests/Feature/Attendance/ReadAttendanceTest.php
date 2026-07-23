<?php

declare(strict_types=1);

use App\Domain\Attendance\PunchDirection;
use App\Models\AttendanceLog;
use App\Models\Employee;
use App\Models\Office;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

it('returns my punches for a month grouped by office-local date', function (): void {
    $office = Office::factory()->create(['timezone' => 'Asia/Manila']);
    $user = User::factory()->create();
    $me = Employee::factory()->for($user)->create(['current_office_id' => $office->id]);

    // 08:00 and 17:00 Manila on 2026-03-02 — stored UTC (00:00 and 09:00).
    AttendanceLog::factory()->create(['employee_id' => $me->id, 'office_id' => $office->id, 'direction' => PunchDirection::In, 'punched_at' => '2026-03-02 00:00:00']);
    AttendanceLog::factory()->create(['employee_id' => $me->id, 'office_id' => $office->id, 'direction' => PunchDirection::Out, 'punched_at' => '2026-03-02 09:00:00']);

    Sanctum::actingAs($user);

    $data = $this->getJson('/api/v1/me/attendance?month=2026-03')->assertOk()->json('data');

    expect($data)->toHaveKey('2026-03-02')
        ->and($data['2026-03-02'])->toHaveCount(2);
});

it('buckets a cross-midnight punch on its own office-local date', function (): void {
    // A night-shift out-punch at 22:00 UTC on the 2nd is 06:00 Manila on the 3rd.
    $office = Office::factory()->create(['timezone' => 'Asia/Manila']);
    $user = User::factory()->create();
    $me = Employee::factory()->for($user)->create(['current_office_id' => $office->id]);

    AttendanceLog::factory()->create(['employee_id' => $me->id, 'office_id' => $office->id, 'direction' => PunchDirection::Out, 'punched_at' => '2026-03-02 22:00:00']);
    Sanctum::actingAs($user);

    $data = $this->getJson('/api/v1/me/attendance?month=2026-03')->assertOk()->json('data');

    // Raw view: no pairing, no business-day. The out-punch lands on its LOCAL date (the 3rd).
    expect($data)->toHaveKey('2026-03-03');
});

it('lets an HR admin read an in-scope employee and 404s out of scope', function (): void {
    $manila = Office::factory()->create(['timezone' => 'Asia/Manila']);
    $cebu = Office::factory()->create(['timezone' => 'Asia/Manila']);
    $hrUser = User::factory()->create();
    Employee::factory()->for($hrUser)->create(['current_office_id' => $manila->id]);
    $hrUser->hrAdminOffices()->attach($manila->id);

    $manilaWorker = Employee::factory()->create(['current_office_id' => $manila->id]);
    $cebuWorker = Employee::factory()->create(['current_office_id' => $cebu->id]);
    Sanctum::actingAs($hrUser);

    $this->getJson("/api/v1/employees/{$manilaWorker->id}/attendance?month=2026-03")->assertOk();
    $this->getJson("/api/v1/employees/{$cebuWorker->id}/attendance?month=2026-03")->assertStatus(404);
});
