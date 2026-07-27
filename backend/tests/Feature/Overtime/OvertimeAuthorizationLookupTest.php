<?php

declare(strict_types=1);

use App\Domain\Overtime\OvertimeAuthorizationLookup;
use App\Models\Employee;
use App\Models\OvertimeDetail;
use App\Models\Request;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function makeOvertimeRequest(Employee $employee, string $date, int $minutes, string $state): void
{
    $request = Request::factory()->create([
        'type' => 'overtime',
        'employee_id' => $employee->id,
        'state' => $state,
        'decision_note' => $state === 'rejected' ? 'rejected in test fixture' : null,
    ]);
    OvertimeDetail::query()->create(['request_id' => $request->id, 'date' => $date, 'minutes' => $minutes]);
}

it('returns 0 when no approved overtime covers the date', function (): void {
    $employee = Employee::factory()->create();
    expect(OvertimeAuthorizationLookup::approvedMinutesFor($employee, '2026-07-15'))->toBe(0);
});

it('ignores non-approved overtime requests', function (): void {
    $employee = Employee::factory()->create();
    makeOvertimeRequest($employee, '2026-07-15', 120, 'pending');
    makeOvertimeRequest($employee, '2026-07-15', 60, 'rejected');
    expect(OvertimeAuthorizationLookup::approvedMinutesFor($employee, '2026-07-15'))->toBe(0);
});

it('sums approved overtime minutes for the date only', function (): void {
    $employee = Employee::factory()->create();
    makeOvertimeRequest($employee, '2026-07-15', 120, 'approved');
    makeOvertimeRequest($employee, '2026-07-15', 30, 'approved');
    makeOvertimeRequest($employee, '2026-07-16', 90, 'approved'); // different date
    expect(OvertimeAuthorizationLookup::approvedMinutesFor($employee, '2026-07-15'))->toBe(150);
});

it('does not count another employee\'s approved overtime', function (): void {
    $employee = Employee::factory()->create();
    $other = Employee::factory()->create();
    makeOvertimeRequest($other, '2026-07-15', 120, 'approved');
    expect(OvertimeAuthorizationLookup::approvedMinutesFor($employee, '2026-07-15'))->toBe(0);
});
