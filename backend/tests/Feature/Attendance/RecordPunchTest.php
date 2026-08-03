<?php

declare(strict_types=1);

use App\Actions\Attendance\RecordPunch;
use App\Actions\Attendance\RecordPunchInput;
use App\Domain\Attendance\PunchDirection;
use App\Domain\Attendance\PunchSource;
use App\Domain\Attendance\PunchVerification;
use App\Exceptions\Domain\DuplicatePunchMinute;
use App\Models\AttendanceLog;
use App\Models\Employee;
use App\Models\Office;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

it('snapshots the current office and stamps server time for a self-service punch', function (): void {
    $office = Office::factory()->create(['ip_allowlist' => null]);
    $employee = Employee::factory()->create(['current_office_id' => $office->id]);

    Carbon::setTestNow('2026-03-02 09:00:00');
    $log = app(RecordPunch::class)->execute(new RecordPunchInput(
        employeeId: $employee->id,
        direction: PunchDirection::In,
        source: PunchSource::Web,
        punchedAt: null,                // server now
        recordedBy: $employee->user_id,
        ipAddress: '198.51.100.4',
        deviceId: null, geoLat: null, geoLng: null,
    ));
    Carbon::setTestNow();

    expect($log->office_id)->toBe($office->id)                    // snapshot
        ->and($log->punched_at->toDateTimeString())->toBe('2026-03-02 09:00:00')
        ->and($log->direction)->toBe(PunchDirection::In)
        ->and($log->verification)->toBe(PunchVerification::Verified);
});

it('stores a manual punch at the supplied time', function (): void {
    $office = Office::factory()->create(['ip_allowlist' => null]);
    $employee = Employee::factory()->create(['current_office_id' => $office->id]);

    $log = app(RecordPunch::class)->execute(new RecordPunchInput(
        employeeId: $employee->id,
        direction: PunchDirection::Out,
        source: PunchSource::Manual,
        punchedAt: Carbon::parse('2026-03-01 17:30:00'),   // HR correcting a missed punch
        recordedBy: User::factory()->create()->id,   // recorded_by is an FK to users, not employees
        ipAddress: null, deviceId: null, geoLat: null, geoLng: null,
    ));

    expect($log->source)->toBe(PunchSource::Manual)
        ->and($log->punched_at->toDateTimeString())->toBe('2026-03-01 17:30:00');
});

it('stores the correct UTC instant for a manual punch supplied with a non-UTC offset', function (): void {
    $office = Office::factory()->create(['ip_allowlist' => null]);
    $employee = Employee::factory()->create(['current_office_id' => $office->id]);

    $log = app(RecordPunch::class)->execute(new RecordPunchInput(
        employeeId: $employee->id,
        direction: PunchDirection::In,
        source: PunchSource::Manual,
        punchedAt: Carbon::parse('2026-07-01T08:00:00+08:00'),   // = 2026-07-01 00:00:00Z
        recordedBy: User::factory()->create()->id,
        ipAddress: null, deviceId: null, geoLat: null, geoLng: null,
    ));

    $stored = DB::table('attendance_logs')->where('id', $log->id)->value('punched_at');

    expect($log->fresh()->punched_at->utc()->toDateTimeString())->toBe('2026-07-01 00:00:00')
        ->and($stored)->toBe('2026-07-01 00:00:00+00');
});

it('stores the exact UTC instant for a self-service punch, unaffected by UTC normalization', function (): void {
    $office = Office::factory()->create(['ip_allowlist' => null]);
    $employee = Employee::factory()->create(['current_office_id' => $office->id]);

    Carbon::setTestNow('2026-03-02 09:00:00');
    $log = app(RecordPunch::class)->execute(new RecordPunchInput(
        employeeId: $employee->id,
        direction: PunchDirection::In,
        source: PunchSource::Web,
        punchedAt: null,                // server now
        recordedBy: $employee->user_id,
        ipAddress: '198.51.100.4',
        deviceId: null, geoLat: null, geoLng: null,
    ));
    Carbon::setTestNow();

    expect($log->fresh()->punched_at->utc()->toDateTimeString())->toBe('2026-03-02 09:00:00');
});

it('flags a punch from an IP outside the office allowlist but still stores it', function (): void {
    $office = Office::factory()->create(['ip_allowlist' => ['203.0.113.0/24']]);
    $employee = Employee::factory()->create(['current_office_id' => $office->id]);

    $log = app(RecordPunch::class)->execute(new RecordPunchInput(
        employeeId: $employee->id,
        direction: PunchDirection::In,
        source: PunchSource::Web,
        punchedAt: null,
        recordedBy: $employee->user_id,
        ipAddress: '198.51.100.9',            // outside the /24
        deviceId: null, geoLat: null, geoLng: null,
    ));

    expect($log->verification)->toBe(PunchVerification::Flagged)
        ->and($log->flag_reason)->toBe('ip_not_allowlisted')
        ->and(AttendanceLog::count())->toBe(1);   // stored, not rejected
});

// duplicate minute -------------------------------------------------------------------

it('refuses a second punch in the same office-local minute', function (): void {
    // EffectivePunches truncates to the minute, so two punches 40 seconds apart are the same
    // minute downstream and collide in PunchPairer. That used to surface as a 500 from inside
    // DB::afterCommit, AFTER the punch was durable — leaving the day with no summary row at
    // all, invisible to CloseCutoff's incomplete-day gate. Refusing at ingestion is the only
    // point that can still hand the caller an error.
    $office = Office::factory()->create(['ip_allowlist' => null, 'timezone' => 'Asia/Manila']);
    $employee = Employee::factory()->create(['current_office_id' => $office->id]);

    $punch = fn (string $at, PunchDirection $direction) => app(RecordPunch::class)->execute(new RecordPunchInput(
        employeeId: $employee->id,
        direction: $direction,
        source: PunchSource::Manual,
        punchedAt: Carbon::parse($at),
        recordedBy: User::factory()->create()->id,
        ipAddress: null, deviceId: null, geoLat: null, geoLng: null,
    ));

    $punch('2026-03-02 00:00:10', PunchDirection::In);

    expect(fn () => $punch('2026-03-02 00:00:50', PunchDirection::Out))
        ->toThrow(DuplicatePunchMinute::class);

    // Append-only holds: the refused write simply never happened, nothing was edited.
    expect(AttendanceLog::count())->toBe(1);
});

it('accepts the very next minute', function (): void {
    // The guard is one minute wide, not a cooldown — an employee punching out at 08:01 after
    // punching in at 08:00 is a (very short) shift, not a double-tap.
    $office = Office::factory()->create(['ip_allowlist' => null, 'timezone' => 'Asia/Manila']);
    $employee = Employee::factory()->create(['current_office_id' => $office->id]);

    $punch = fn (string $at, PunchDirection $direction) => app(RecordPunch::class)->execute(new RecordPunchInput(
        employeeId: $employee->id,
        direction: $direction,
        source: PunchSource::Manual,
        punchedAt: Carbon::parse($at),
        recordedBy: User::factory()->create()->id,
        ipAddress: null, deviceId: null, geoLat: null, geoLng: null,
    ));

    $punch('2026-03-02 00:00:59', PunchDirection::In);
    $punch('2026-03-02 00:01:00', PunchDirection::Out);

    expect(AttendanceLog::count())->toBe(2);
});

it('does not refuse a punch in the same minute belonging to a different employee', function (): void {
    $office = Office::factory()->create(['ip_allowlist' => null, 'timezone' => 'Asia/Manila']);
    $first = Employee::factory()->create(['current_office_id' => $office->id]);
    $second = Employee::factory()->create(['current_office_id' => $office->id]);

    $punch = fn (Employee $employee) => app(RecordPunch::class)->execute(new RecordPunchInput(
        employeeId: $employee->id,
        direction: PunchDirection::In,
        source: PunchSource::Manual,
        punchedAt: Carbon::parse('2026-03-02 00:00:10'),
        recordedBy: User::factory()->create()->id,
        ipAddress: null, deviceId: null, geoLat: null, geoLng: null,
    ));

    $punch($first);
    $punch($second);

    expect(AttendanceLog::count())->toBe(2);
});
