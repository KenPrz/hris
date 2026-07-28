<?php

declare(strict_types=1);

use App\Domain\Pay\SummaryLineKind;
use App\Models\CutoffPeriod;
use App\Models\DailyAttendanceSummary;
use App\Models\Employee;
use App\Models\EmploymentRecord;
use App\Models\Office;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

/*
| M7b Task 2: the HTTP surface for the payroll export — closed-only, OfficeScope-gated,
| mirroring CutoffEndpointsTest's setup and 404-not-403 discipline.
*/

function exportOffice(): Office
{
    return Office::factory()->create();
}

function exportHrAdminOf(Office ...$offices): User
{
    $user = User::factory()->create();

    foreach ($offices as $office) {
        $user->hrAdminOffices()->attach($office->id);
    }

    return $user;
}

it('exports a closed period\'s payroll data, reconciling a known employee\'s line minutes', function (): void {
    $office = exportOffice();
    $hrUser = exportHrAdminOf($office);
    $employee = Employee::factory()->create(['current_office_id' => $office->id]);
    EmploymentRecord::factory()->create([
        'employee_id' => $employee->id,
        'office_id' => $office->id,
        'effective_from' => '2026-01-01',
        'base_rate_cents' => 61000,
    ]);

    $period = CutoffPeriod::factory()->closed()->create([
        'office_id' => $office->id,
        'start_date' => '2026-07-01',
        'end_date' => '2026-07-15',
    ]);

    $day = DailyAttendanceSummary::factory()->create([
        'employee_id' => $employee->id,
        'office_id' => $office->id,
        'date' => '2026-07-05',
        'worked_minutes' => 480,
        'late_minutes' => 0,
        'undertime_minutes' => 0,
        'unpaid_overtime_minutes' => 0,
    ]);
    $day->lines()->create(['kind' => SummaryLineKind::RegularDay->value, 'minutes' => 480, 'applied_bp' => 10000]);

    Sanctum::actingAs($hrUser);

    $response = $this->getJson("/api/v1/office/cutoffs/{$period->id}/export")
        ->assertOk();

    expect($response->json('data.period.id'))->toBe($period->id)
        ->and($response->json('data.period.office_id'))->toBe($office->id)
        ->and($response->json('data.period.start_date'))->toBe('2026-07-01')
        ->and($response->json('data.period.end_date'))->toBe('2026-07-15')
        ->and($response->json('data.period.state'))->toBe('closed');

    $employees = $response->json('data.employees');
    expect($employees)->toHaveCount(1);

    $export = $employees[0];
    expect($export['employee']['id'])->toBe($employee->id)
        ->and($export['employee']['employee_no'])->toBe($employee->employee_no)
        ->and($export['employee']['base_rate_cents'])->toBe(61000)
        ->and($export['totals']['worked_minutes'])->toBe(480)
        ->and($export['has_incomplete_days'])->toBeFalse();

    $regularLine = collect($export['lines'])->firstWhere('kind', SummaryLineKind::RegularDay->value);
    expect($regularLine)->not->toBeNull()
        ->and($regularLine['minutes'])->toBe(480)
        ->and($regularLine['applied_bp'])->toBe(10000);
});

it('422s exporting an open period', function (): void {
    $office = exportOffice();
    $hrUser = exportHrAdminOf($office);
    $period = CutoffPeriod::factory()->create(['office_id' => $office->id, 'state' => 'open']);

    Sanctum::actingAs($hrUser);

    $this->getJson("/api/v1/office/cutoffs/{$period->id}/export")
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'period_not_exportable');
});

it('404s exporting a period belonging to an office the admin does not administer, identically to a fabricated period', function (): void {
    $manila = exportOffice();
    $cebu = exportOffice();
    $hrUser = exportHrAdminOf($manila);
    $outOfScopePeriod = CutoffPeriod::factory()->closed()->create(['office_id' => $cebu->id]);

    Sanctum::actingAs($hrUser);

    $outOfScope = $this->getJson("/api/v1/office/cutoffs/{$outOfScopePeriod->id}/export")
        ->assertStatus(404);

    $fabricated = $this->getJson('/api/v1/office/cutoffs/'.(string) Str::uuid7().'/export')
        ->assertStatus(404);

    $outOfScope->assertExactJson($fabricated->json());
    $outOfScope->assertJsonPath('error.code', 'not_found');
});

it('requires authentication', function (): void {
    $office = exportOffice();
    $period = CutoffPeriod::factory()->closed()->create(['office_id' => $office->id]);

    $this->getJson("/api/v1/office/cutoffs/{$period->id}/export")
        ->assertStatus(401)
        ->assertJsonPath('error.code', 'unauthenticated');
});
