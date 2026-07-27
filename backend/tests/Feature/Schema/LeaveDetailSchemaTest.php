<?php

declare(strict_types=1);

use App\Models\Employee;
use App\Models\LeaveType;
use App\Models\Office;
use App\Models\Request;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

function leaveDetailSchemaRow(string $requestId, string $leaveTypeId, array $overrides = []): array
{
    return array_merge([
        'request_id' => $requestId,
        'leave_type_id' => $leaveTypeId,
        'start_date' => '2026-08-10',
        'end_date' => '2026-08-10',
        'day_part' => 'full',
        'amount_minutes' => 480,
    ], $overrides);
}

it('round-trips a leave detail row', function (): void {
    $office = Office::factory()->create();
    $employee = Employee::factory()->create(['current_office_id' => $office->id]);
    $leaveType = LeaveType::factory()->create(['office_id' => $office->id]);
    $request = Request::factory()->for($employee)->create();

    DB::table('leave_details')->insert(leaveDetailSchemaRow($request->id, $leaveType->id));

    $row = DB::table('leave_details')->where('request_id', $request->id)->first();

    expect($row->leave_type_id)->toBe($leaveType->id)
        ->and($row->start_date)->toBe('2026-08-10')
        ->and($row->end_date)->toBe('2026-08-10')
        ->and($row->day_part)->toBe('full')
        ->and($row->amount_minutes)->toBe(480);
});

it('rejects a day_part outside full/half via the CHECK constraint', function (): void {
    $office = Office::factory()->create();
    $employee = Employee::factory()->create(['current_office_id' => $office->id]);
    $leaveType = LeaveType::factory()->create(['office_id' => $office->id]);
    $request = Request::factory()->for($employee)->create();

    expect(fn () => DB::table('leave_details')->insert(
        leaveDetailSchemaRow($request->id, $leaveType->id, ['day_part' => 'bogus'])
    ))->toThrow(QueryException::class);
});

it('rejects a non-positive amount_minutes via the CHECK constraint', function (): void {
    $office = Office::factory()->create();
    $employee = Employee::factory()->create(['current_office_id' => $office->id]);
    $leaveType = LeaveType::factory()->create(['office_id' => $office->id]);
    $request = Request::factory()->for($employee)->create();

    expect(fn () => DB::table('leave_details')->insert(
        leaveDetailSchemaRow($request->id, $leaveType->id, ['amount_minutes' => 0])
    ))->toThrow(QueryException::class);
});

it('rejects an end_date before start_date via the CHECK constraint', function (): void {
    $office = Office::factory()->create();
    $employee = Employee::factory()->create(['current_office_id' => $office->id]);
    $leaveType = LeaveType::factory()->create(['office_id' => $office->id]);
    $request = Request::factory()->for($employee)->create();

    expect(fn () => DB::table('leave_details')->insert(
        leaveDetailSchemaRow($request->id, $leaveType->id, [
            'start_date' => '2026-08-10',
            'end_date' => '2026-08-09',
        ])
    ))->toThrow(QueryException::class);
});

it('cascades delete from requests to leave_details', function (): void {
    $office = Office::factory()->create();
    $employee = Employee::factory()->create(['current_office_id' => $office->id]);
    $leaveType = LeaveType::factory()->create(['office_id' => $office->id]);
    $request = Request::factory()->for($employee)->create();

    DB::table('leave_details')->insert(leaveDetailSchemaRow($request->id, $leaveType->id));

    $request->delete();

    expect(DB::table('leave_details')->where('request_id', $request->id)->exists())->toBeFalse();
});
