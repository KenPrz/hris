<?php

declare(strict_types=1);

use App\Actions\Attendance\RecordPunch;
use App\Actions\Attendance\RecordPunchInput;
use App\Domain\Attendance\PunchDirection;
use App\Domain\Attendance\PunchSource;
use App\Domain\Attendance\PunchVerification;
use App\Models\AttendanceLog;
use App\Models\Employee;
use App\Models\Office;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;

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
