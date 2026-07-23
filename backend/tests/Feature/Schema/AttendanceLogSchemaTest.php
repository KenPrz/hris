<?php

declare(strict_types=1);

use App\Domain\Attendance\PunchDirection;
use App\Domain\Attendance\PunchSource;
use App\Domain\Attendance\PunchVerification;
use App\Models\AttendanceLog;
use App\Models\Employee;
use App\Models\Office;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

it('stores a punch with typed enums and a snapshot office', function (): void {
    $office = Office::factory()->create();
    $employee = Employee::factory()->create();

    $log = AttendanceLog::factory()->create([
        'employee_id' => $employee->id,
        'office_id' => $office->id,
        'direction' => PunchDirection::In,
        'source' => PunchSource::Web,
        'verification' => PunchVerification::Verified,
    ]);

    $fresh = $log->fresh();
    expect($fresh->direction)->toBe(PunchDirection::In)          // cast back to the enum
        ->and($fresh->source)->toBe(PunchSource::Web)
        ->and($fresh->verification)->toBe(PunchVerification::Verified)
        ->and($fresh->office_id)->toBe($office->id)
        ->and($fresh->employee->is($employee))->toBeTrue();
});

it('rejects a direction outside the CHECK constraint', function (): void {
    $employee = Employee::factory()->create();
    $office = Office::factory()->create();

    // A raw insert bypassing the enum cast must still be rejected by the DB CHECK.
    expect(fn () => DB::table('attendance_logs')->insert([
        'id' => (string) Illuminate\Support\Str::uuid7(),
        'employee_id' => $employee->id,
        'office_id' => $office->id,
        'punched_at' => now(),
        'direction' => 'sideways',           // not in ('in','out')
        'source' => 'web',
        'verification' => 'verified',
        'created_at' => now(),
    ]))->toThrow(Illuminate\Database\QueryException::class);
});

it('keeps the CHECK value lists in sync with the enum cases', function (): void {
    // If someone adds an enum case without widening the CHECK (or vice versa), this fails.
    expect(array_map(fn ($c) => $c->value, PunchDirection::cases()))->toBe(['in', 'out'])
        ->and(array_map(fn ($c) => $c->value, PunchSource::cases()))->toBe(['web', 'manual', 'device', 'adjustment'])
        ->and(array_map(fn ($c) => $c->value, PunchVerification::cases()))->toBe(['verified', 'flagged']);
});
