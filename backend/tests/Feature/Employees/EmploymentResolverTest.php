<?php

declare(strict_types=1);

use App\Domain\Employment\EmploymentResolver;
use App\Models\Employee;
use App\Models\EmploymentRecord;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;

uses(RefreshDatabase::class);

it('returns the record whose range covers the date', function (): void {
    $employee = Employee::factory()->create();
    EmploymentRecord::factory()->for($employee)->create(['effective_from' => '2026-01-01', 'is_art82_exempt' => false]);
    EmploymentRecord::factory()->for($employee)->create(['effective_from' => '2026-06-01', 'is_art82_exempt' => true]);

    // Before the promotion: the January row (not exempt).
    expect(EmploymentResolver::on($employee, Carbon::parse('2026-03-15'))->is_art82_exempt)->toBeFalse()
        // On/after the promotion: the June row (exempt).
        ->and(EmploymentResolver::on($employee, Carbon::parse('2026-06-01'))->is_art82_exempt)->toBeTrue()
        ->and(EmploymentResolver::on($employee, Carbon::parse('2026-09-01'))->is_art82_exempt)->toBeTrue();
});

it('returns null before the earliest record', function (): void {
    $employee = Employee::factory()->create();
    EmploymentRecord::factory()->for($employee)->create(['effective_from' => '2026-01-01']);

    expect(EmploymentResolver::on($employee, Carbon::parse('2025-12-31')))->toBeNull();
});
